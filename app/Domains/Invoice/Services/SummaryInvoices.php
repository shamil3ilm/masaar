<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Services;

use App\Domains\Compliance\Engine\Contracts\Jurisdiction;
use App\Domains\Compliance\Engine\Services\JurisdictionResolver;
use App\Domains\Invoice\Enums\DocumentType;
use App\Domains\Invoice\Models\Customer;
use App\Domains\Invoice\Models\DeliveryNote;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Invoice\Support\UnitCode;
use App\Domains\Organization\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * A month's deliveries to one customer on one summary tax invoice.
 *
 * Deliveries are recorded as delivery notes, which are not tax documents.
 * Picking a customer and a period gathers the notes not yet invoiced and
 * drafts ONE invoice for them: each delivery's lines, named with the note
 * and its date - the summary "showing the date of each supply separately"
 * (ZATCA Guideline for Tax Invoicing §4.4, Example 8) - the period as its
 * supply date and supply end date, and the fields the country adds to make
 * it a summary (SpecialSupplies::summary). The notes are then the
 * invoice's: none is invoiced twice, which both countries forbid ("may not
 * issue separate tax invoices for each individual supply", Guideline §4.4;
 * UAE Executive Regulation Art 59(6)). If the draft is deleted they are
 * free again (the foreign key clears).
 *
 * The draft is checked by the country's invoice rules (CountryDraft) and
 * issued like any other draft.
 */
class SummaryInvoices
{
    public function __construct(
        private readonly JurisdictionResolver $jurisdictions,
        private readonly InvoiceDrafter $drafter,
    ) {}

    /**
     * The rules a delivery note is recorded under: a customer of the
     * organization's, a date, and lines priced net in the country's
     * categories.
     *
     * @return array<string, list<mixed>>
     */
    public function noteRules(Organization $organization): array
    {
        $rules = $this->jurisdictions->forRules($organization)->invoiceRules();

        return [
            'customer_id' => ['required', 'string', 'uuid', Rule::exists('customers', 'id')->where('org_id', $organization->id)->whereNull('deleted_at')],
            'number' => ['nullable', 'string', 'max:64'],
            'delivered_on' => ['required', 'date'],
            'reference' => ['nullable', 'string', 'max:127'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.description' => ['required', 'string', 'max:200'],
            'lines.*.description_ar' => ['nullable', 'string', 'max:200'],
            'lines.*.quantity' => ['required', 'numeric', 'min:0.001', 'max:999999999.999', 'decimal:0,3'],
            'lines.*.unit_code' => ['nullable', 'string', Rule::in(UnitCode::accepted())],
            'lines.*.unit_price' => ['required', 'numeric', 'min:0', 'max:9999999999.99', 'decimal:0,2'],
            'lines.*.tax_category' => ['nullable', 'string', Rule::in($rules->taxCategories())],
            'lines.*.tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'lines.*.exempt_code' => ['nullable', 'string', Rule::in($rules->exemptionCodes())],
            'lines.*.exempt_reason' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * Record a delivery, as validated by noteRules(). Numbered DN-00001
     * and on when no number is given; a number already on a kept note is
     * refused.
     *
     * @param  array<string, mixed>  $validated
     *
     * @throws ValidationException
     */
    public function record(Organization $organization, array $validated, ?string $branchId = null): DeliveryNote
    {
        $rules = $this->jurisdictions->forRules($organization)->invoiceRules();

        return DB::transaction(function () use ($organization, $validated, $branchId, $rules): DeliveryNote {
            $number = filled($validated['number'] ?? null) ? trim((string) $validated['number']) : $this->nextNumber((string) $organization->id);

            if ($this->notes((string) $organization->id)->where('number', $number)->exists()) {
                throw ValidationException::withMessages(['number' => __('A delivery note numbered :number is already recorded.', ['number' => $number])]);
            }

            $lines = [];
            $net = '0';

            foreach (array_values((array) $validated['lines']) as $line) {
                $category = (string) ($line['tax_category'] ?? 'S');
                $kept = [
                    'description' => (string) $line['description'],
                    'description_ar' => filled($line['description_ar'] ?? null) ? (string) $line['description_ar'] : null,
                    'quantity' => (string) $line['quantity'],
                    'unit_code' => UnitCode::normalise($line['unit_code'] ?? null),
                    'unit_price' => number_format((float) $line['unit_price'], 2, '.', ''),
                    'tax_category' => $category,
                    'tax_rate' => $category === 'S' ? (string) ($line['tax_rate'] ?? $rules->standardRate()) : '0',
                    'exempt_code' => $line['exempt_code'] ?? null,
                    'exempt_reason' => $line['exempt_reason'] ?? null,
                ];
                $lines[] = $kept;
                $net = bcadd($net, self::round(bcmul($kept['quantity'], $kept['unit_price'], 6)), 2);
            }

            return DeliveryNote::withoutTenantScope(fn () => DeliveryNote::query()->create([
                'org_id' => $organization->id,
                'branch_id' => $branchId,
                'customer_id' => $validated['customer_id'],
                'number' => $number,
                'delivered_on' => $validated['delivered_on'],
                'reference' => $validated['reference'] ?? null,
                'lines' => $lines,
                'net_amount' => $net,
            ]));
        });
    }

    /**
     * The organization's delivery notes, the latest first.
     *
     * @return LengthAwarePaginator<int, DeliveryNote>
     */
    public function list(string $organizationId, ?string $customerId = null, ?bool $invoiced = null, int $perPage = 25): LengthAwarePaginator
    {
        return $this->notes($organizationId)
            ->with(['customer:id,name,name_ar', 'invoice:id,invoice_number,status'])
            ->when($customerId !== null, fn ($query) => $query->where('customer_id', $customerId))
            ->when($invoiced !== null, fn ($query) => $invoiced ? $query->whereNotNull('invoice_id') : $query->whereNull('invoice_id'))
            ->orderByDesc('delivered_on')->orderByDesc('created_at')
            ->paginate($perPage);
    }

    /**
     * The delivery notes a summary invoice took.
     *
     * @return list<DeliveryNote>
     */
    public function on(Invoice $invoice): array
    {
        return $this->notes((string) $invoice->org_id)->where('invoice_id', $invoice->id)->orderBy('delivered_on')->orderBy('number')->get()->all();
    }

    public function find(string $organizationId, string $id): ?DeliveryNote
    {
        return $this->notes($organizationId)->find($id);
    }

    /**
     * Remove a note entered in error, while no invoice has taken it.
     *
     * @throws ValidationException
     */
    public function remove(DeliveryNote $note): void
    {
        if ($note->invoiced()) {
            throw ValidationException::withMessages(['note' => __('This delivery note is on invoice :number and cannot be removed.', ['number' => $note->invoice?->invoice_number ?? '-'])]);
        }

        $note->delete();
    }

    /**
     * The open notes a summary for this customer and period would take.
     *
     * @return list<DeliveryNote>
     */
    public function open(string $organizationId, string $customerId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        return $this->notes($organizationId)
            ->where('customer_id', $customerId)
            ->whereNull('invoice_id')
            ->whereBetween('delivered_on', [$from->toDateString(), $to->toDateString()])
            ->orderBy('delivered_on')->orderBy('number')
            ->get()->all();
    }

    /** The last day the summary for a period ending on $to may be issued, by the country's law. */
    public function issueBy(Organization $organization, CarbonImmutable $to): CarbonImmutable
    {
        return $this->jurisdictions->forRules($organization)->specialSupplies()->summaryIssueBy($to);
    }

    /**
     * Draft the summary invoice for a customer's open notes in a period of
     * at most one calendar month (KSA IR Art 53(4); UAE Executive
     * Regulation Art 59(13)(b) counts from the end of the calendar month).
     *
     * @throws ValidationException naming what is refused
     */
    public function draft(Organization $organization, string $customerId, CarbonImmutable $from, CarbonImmutable $to, CarbonImmutable $issueDate, ?CarbonImmutable $dueDate = null, ?string $branchId = null): Invoice
    {
        if ($to->lt($from) || $from->format('Y-m') !== $to->format('Y-m')) {
            throw ValidationException::withMessages(['to' => __('A summary invoice covers one calendar month at most: choose a period within one month.')]);
        }

        if ($issueDate->lt($to)) {
            throw ValidationException::withMessages(['issue_date' => __('A summary invoice is issued once its period has ended.')]);
        }

        $customer = Customer::withoutTenantScope(fn () => Customer::query()->where('org_id', $organization->id)->find($customerId))
            ?? throw ValidationException::withMessages(['customer_id' => __('Choose one of your customers.')]);
        $notes = $this->open((string) $organization->id, $customerId, $from, $to);

        if ($notes === []) {
            throw ValidationException::withMessages(['customer_id' => __('This customer has no delivery notes to invoice in that period.')]);
        }

        $jurisdiction = $this->jurisdictions->forRules($organization);
        $checked = CountryDraft::check($jurisdiction->invoiceRules(), $this->payload($jurisdiction, $customer, $notes, $from, $to, $issueDate, $dueDate), 'summary');

        return DB::transaction(function () use ($organization, $notes, $checked, $branchId): Invoice {
            $ids = array_map(fn (DeliveryNote $note): string => $note->id, $notes);
            $locked = DeliveryNote::withoutTenantScope(fn () => DeliveryNote::query()->whereKey($ids)->lockForUpdate()->get());

            if ($locked->contains(fn (DeliveryNote $note): bool => $note->invoiced()) || $locked->count() !== count($ids)) {
                throw ValidationException::withMessages(['customer_id' => __('These delivery notes changed meanwhile. Try again.')]);
            }

            $invoice = $this->drafter->draft($checked['draft'], (string) $organization->id, $branchId, $checked['countryFields']);

            foreach ($locked as $note) {
                $note->forceFill(['invoice_id' => $invoice->id])->save();
            }

            return $invoice;
        });
    }

    /**
     * The invoice request: one line per delivered line, in the fields every
     * country's rules take, then the country's summary fields.
     *
     * @param  list<DeliveryNote>  $notes
     * @return array<string, mixed>
     */
    private function payload(Jurisdiction $jurisdiction, Customer $customer, array $notes, CarbonImmutable $from, CarbonImmutable $to, CarbonImmutable $issueDate, ?CarbonImmutable $dueDate): array
    {
        $lines = [];

        foreach ($notes as $note) {
            $said = ['number' => $note->number, 'date' => $note->delivered_on->toDateString()];

            foreach ((array) $note->lines as $line) {
                $english = mb_substr($line['description'].' - '.__('delivery :number on :date', $said, 'en'), 0, 255);
                $lines[] = array_filter([
                    'description' => $english,
                    'description_ar' => filled($line['description_ar'] ?? null) ? mb_substr($line['description_ar'].' - '.__('delivery :number on :date', $said, 'ar'), 0, 255) : null,
                    'item_name' => mb_substr((string) $line['description'], 0, 255),
                    'quantity' => (string) $line['quantity'],
                    'unit_code' => $line['unit_code'] ?? null,
                    'unit_price' => (string) $line['unit_price'],
                    'tax_category' => $line['tax_category'] ?? null,
                    'tax_rate' => $line['tax_rate'] ?? null,
                    'exempt_code' => $line['exempt_code'] ?? null,
                    'exempt_reason' => $line['exempt_reason'] ?? null,
                ], fn (mixed $value): bool => $value !== null && $value !== '');
            }
        }

        return $jurisdiction->specialSupplies()->summary([
            ...$jurisdiction->recurringSupply()->buyerFrom($customer),
            'document_type' => DocumentType::Invoice->value,
            'issue_date' => $issueDate->toDateString(),
            'due_date' => ($dueDate ?? $issueDate)->toDateString(),
            'supply_date' => $from->toDateString(),
            'supply_end_date' => $to->toDateString(),
            'currency' => $jurisdiction->currency(),
            'prices_include_vat' => false,
            'notes' => __('Summary invoice for deliveries :from to :to.', ['from' => $from->toDateString(), 'to' => $to->toDateString()], 'ar')
                ."\n".__('Summary invoice for deliveries :from to :to.', ['from' => $from->toDateString(), 'to' => $to->toDateString()], 'en'),
            'lines' => $lines,
        ]);
    }

    /** @return Builder<DeliveryNote> */
    private function notes(string $organizationId)
    {
        return DeliveryNote::withoutTenantScope(fn () => DeliveryNote::query()->where('org_id', $organizationId));
    }

    private function nextNumber(string $organizationId): string
    {
        $count = DeliveryNote::withoutTenantScope(fn () => DeliveryNote::withTrashed()->where('org_id', $organizationId)->count());

        return 'DN-'.str_pad((string) ($count + 1), 5, '0', STR_PAD_LEFT);
    }

    private static function round(string $value): string
    {
        return bcadd(bcadd($value, bccomp($value, '0', 6) < 0 ? '-0.005' : '0.005', 6), '0', 2);
    }
}
