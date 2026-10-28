<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Services;

use App\Domains\Audit\Services\AuditService;
use App\Domains\Auth\Models\User;
use App\Domains\Compliance\Engine\Contracts\Jurisdiction;
use App\Domains\Compliance\Engine\DTOs\IssueOptions;
use App\Domains\Compliance\Engine\Services\ComplianceEngine;
use App\Domains\Invoice\Enums\AdjustmentKind;
use App\Domains\Invoice\Enums\DocumentType;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Invoice\Models\InvoiceAdjustment;
use App\Domains\Invoice\Models\InvoiceLine;
use App\Domains\Invoice\Support\AdjustmentPlan;
use App\Domains\Invoice\Support\ShareAllocation;
use App\Domains\Organization\Models\Organization;
use App\Domains\Platform\Approvals\OrganizationApprovals;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * A return, an exchange, a replacement or a correction on an issued invoice,
 * guided: the person says what happened, and the documents it calls for are
 * worked out, shown, and made - never a change to the invoice itself, which
 * once issued is not changed (ZATCA E-Invoicing Detailed Guidelines 5.6
 * p27; UAE Cabinet Decision No. 52 of 2017 Art 60): everything is a new
 * document naming it, or a record beside it.
 *
 *   return            the whole invoice credited, its lines, discount and
 *                     charges as they were (KSA VAT Implementing
 *                     Regulations Art 40(1)(d); UAE Decree-Law Art 61(1)(d))
 *   partial_return    the lines and quantities returned, credited at what
 *                     they were charged
 *   discount          damaged goods the customer keeps, at an amount off
 *                     before VAT or a percentage, over the lines chosen
 *                     (KSA Art 40(1)(c): "an additional discount after the
 *                     sale was made"; UAE Art 61(1)(c))
 *   exchange          the lines returned credited, and a new draft invoice
 *                     for the items given instead
 *   price_difference  an item replaced by another at another price: the
 *                     difference, a debit note (or the UAE's new tax
 *                     invoice, Art 62(1)) when the new one costs more, a
 *                     credit note when less, nothing when the same
 *   replacement       a faulty item replaced free by the same item: no tax
 *                     document - the supply and its consideration are what
 *                     they were (KSA Art 40(1) lists no such event) - only
 *                     this record, with why and how many, audited
 *   buyer_correction  the buyer's details wrong after issue: the whole
 *                     invoice credited (KSA-10: "In case of change in
 *                     Seller's or Buyer's information") and a new draft
 *                     invoice to the corrected buyer, its lines as they were
 *
 * What a returned quantity is worth is what it was charged: the line's net
 * for that share of its quantity, times what the document discount and
 * charges made of the lines (the invoice's taxable amount over the lines'
 * net), to the halalah. Quantities already returned on earlier adjustments
 * cannot be returned again.
 *
 * The country decides the rest (Jurisdiction::adjustments()): whether this
 * invoice can be adjusted now (in Saudi Arabia only once ZATCA accepted
 * it), what an increase is, each note's reason, and the request each
 * document is checked as (CountryDraft). A note follows its original's type
 * - simplified or standard - because its fields start from the original.
 * Each document is drafted at the original's branch and issued as the
 * portal issues one: the country's issuing step checks, again, the
 * original's acceptance and the cap on what may be credited
 * (VatPeriodTracker); a note the organization holds for approval waits for
 * it (NoteIssuing); a note issued now is dated today, so it lands in the
 * current VAT period, as an adjustment where its original's period has
 * passed. A note refused at issue leaves nothing: every document drafted
 * for it is discarded and nothing is recorded.
 */
class InvoiceAdjustments
{
    /** A role in the plan: the credit note, the increase, the new sale. */
    public const CREDIT = 'credit';

    public const INCREASE = 'increase';

    public const INVOICE = 'invoice';

    /** Lines of replacement items one request may give. */
    public const MAX_ITEMS = 50;

    public function __construct(
        private readonly ComplianceEngine $engine,
        private readonly InvoiceDrafter $drafter,
        private readonly InvoicePreview $preview,
        private readonly NoteIssuing $notes,
        private readonly OrganizationApprovals $approvals,
        private readonly AuditService $audit,
    ) {}

    /**
     * What a request says, as both the portal and the API take it.
     *
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'kind' => ['required', Rule::in(array_map(fn (AdjustmentKind $kind): string => $kind->value, AdjustmentKind::requested()))],
            'lines' => ['nullable', 'array', 'max:1000'],
            'lines.*.line' => ['required', 'integer', 'min:1'],
            'lines.*.quantity' => ['nullable', 'numeric', 'min:0', 'max:999999999.999', 'decimal:0,3'],
            'discount_amount' => ['nullable', 'numeric', 'gt:0', 'max:9999999999.99', 'decimal:0,2'],
            'discount_percent' => ['nullable', 'numeric', 'gt:0', 'max:100', 'decimal:0,2'],
            'items' => ['nullable', 'array', 'max:'.self::MAX_ITEMS],
            'items.*.description' => ['required', 'string', 'max:255'],
            'items.*.description_ar' => ['nullable', 'string', 'max:255'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.001', 'max:999999999.999', 'decimal:0,3'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0', 'max:9999999999.99', 'decimal:0,2'],
            'items.*.tax_category' => ['nullable', 'string', 'max:5'],
            'items.*.tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'items.*.exempt_code' => ['nullable', 'string', 'max:50'],
            'items.*.exempt_reason' => ['nullable', 'string', 'max:255'],
            'buyer' => ['nullable', 'array'],
            'buyer.buyer_name' => ['nullable', 'string', 'max:255'],
            'buyer.buyer_name_ar' => ['nullable', 'string', 'max:255'],
            'buyer.buyer_vat_number' => ['nullable', 'string', 'max:50'],
            'buyer.buyer_id' => ['nullable', 'string', 'max:50'],
            'buyer.buyer_id_scheme' => ['nullable', 'string', 'max:10'],
            'buyer.buyer_address' => ['nullable', 'array'],
            'buyer.buyer_address.*' => ['nullable', 'string', 'max:255'],
            'buyer.buyer_endpoint' => ['nullable', 'string', 'max:100'],
            'buyer.buyer_subdivision' => ['nullable', 'string', 'max:50'],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * What the request will make, checked by the country's rules, nothing
     * stored.
     *
     * @param  array<string, mixed>  $input  as rules() validated it
     *
     * @throws ValidationException naming what is wrong
     * @throws DomainException when this invoice cannot be adjusted now
     */
    public function plan(Organization $organization, Invoice $original, array $input): AdjustmentPlan
    {
        $original->loadMissing(['lines.charges', 'charges']);
        $jurisdiction = $this->engine->jurisdictionFor($organization);
        $ports = $jurisdiction->adjustments();

        if (($refusal = $ports->refusal($original)) !== null) {
            throw new DomainException($refusal);
        }

        $kind = AdjustmentKind::from((string) $input['kind']);
        $today = CarbonImmutable::now($jurisdiction->timezone())->toDateString();
        $note = trim((string) ($input['note'] ?? '')) ?: null;
        $returned = $kind->returnsLines() ? $this->returned($original, (array) ($input['lines'] ?? [])) : [];
        $documents = [];
        $details = array_filter(['lines' => array_map(fn (array $line): array => [
            'line' => $line['position'], 'description' => $line['description'], 'quantity' => $line['quantity'],
        ], $returned), 'note' => $note]);

        $document = function (string $role, DocumentType $type, ?array $lines, array $buyer = [], ?bool $issue = null) use (&$documents, $ports, $original, $kind, $today, $jurisdiction): void {
            $fields = $ports->document($original, $type, $kind, $lines, $today, $buyer);
            $checked = CountryDraft::check($jurisdiction->invoiceRules(), $fields, $role);
            $totals = $this->preview->of($checked['draft'], $jurisdiction->vatTreatments());

            $documents[] = [
                'role' => $role,
                'type' => $type->value,
                'issue' => $issue ?? $role !== self::INVOICE,
                'draft' => $checked['draft'],
                'countryFields' => $checked['countryFields'],
                'totals' => ['subtotal' => $totals['subtotal'], 'tax' => $totals['tax'], 'total' => $totals['total']],
                'lines' => array_map(fn (array $line): array => [
                    'description' => (string) ($line['description'] ?? ''),
                    'quantity' => (string) $line['quantity'],
                    'unit_price' => (string) $line['unit_price'],
                    'tax_rate' => (string) ($line['tax_rate'] ?? ''),
                ], array_values((array) $checked['draft']['lines'])),
            ];
        };

        switch ($kind) {
            case AdjustmentKind::Return:
                $this->assertNothingReturned($original);
                $document(self::CREDIT, DocumentType::CreditNote, null);
                break;

            case AdjustmentKind::PartialReturn:
                $document(self::CREDIT, DocumentType::CreditNote, $this->valued($original, $returned));
                break;

            case AdjustmentKind::Discount:
                [$lines, $details['discount']] = $this->discounted($original, $input);
                $document(self::CREDIT, DocumentType::CreditNote, $lines);
                break;

            case AdjustmentKind::Exchange:
                $items = $this->items($input, $returned[0]);
                $details['items'] = $this->itemDetails($items);
                $document(self::CREDIT, DocumentType::CreditNote, $this->valued($original, $returned));
                $document(self::INVOICE, DocumentType::Invoice, $items);
                break;

            case AdjustmentKind::PriceDifference:
                $items = $this->items($input, $returned[0]);
                $details['items'] = $this->itemDetails($items);
                $was = $this->sum($this->valued($original, $returned));
                $now = $this->sum($items);
                $difference = bcsub($now, $was, 2);
                $details['difference'] = $difference;

                if (bccomp($difference, '0', 2) !== 0) {
                    $increase = bccomp($difference, '0', 2) > 0;
                    $document($increase ? self::INCREASE : self::CREDIT, $increase ? $ports->increase() : DocumentType::CreditNote,
                        [$this->differenceLine($returned, $items, ltrim($difference, '-'))], [], true);
                }
                break;

            case AdjustmentKind::Replacement:
                if ($note === null) {
                    throw ValidationException::withMessages(['note' => __('Say why the item is replaced: a free replacement is recorded with its reason.')]);
                }
                break;

            case AdjustmentKind::BuyerCorrection:
                $buyer = array_filter((array) ($input['buyer'] ?? []), fn ($value): bool => $value !== null && $value !== '' && $value !== []);

                if ($buyer === []) {
                    throw ValidationException::withMessages(['buyer' => __('Give the buyer\'s details as they should be.')]);
                }

                $this->assertNothingReturned($original);
                $details['buyer'] = $buyer;
                $document(self::CREDIT, DocumentType::CreditNote, null);
                $document(self::INVOICE, DocumentType::Invoice, null, $buyer);
                break;

            case AdjustmentKind::BadDebtRecovery:
                throw new DomainException(__('A payment after bad-debt relief is recorded on the sale in Books.'));
        }

        $credit = collect($documents)->firstWhere('role', '!=', self::INVOICE);

        return new AdjustmentPlan($kind, $credit === null ? $note : (string) ($credit['draft']['adjustment_reason'] ?? $credit['countryFields']['jurisdiction_data']['credit_reason'] ?? $note), $details, $documents);
    }

    /**
     * Make what plan() showed: draft each document at the original's branch,
     * issue each one that is issued now - or hold it for approval - and
     * record the adjustment. A note refused at issue discards everything
     * drafted for it.
     *
     * @param  array<string, mixed>  $input
     * @return array{adjustment: InvoiceAdjustment, documents: list<array{role: string, invoice: Invoice, status: string, approval: array<string, mixed>|null, message: string|null}>}
     *
     * @throws DomainException with the refusal, when a note could not be issued
     */
    public function apply(Organization $organization, Invoice $original, array $input, ?User $person, bool $integration): array
    {
        $plan = $this->plan($organization, $original, $input);
        $jurisdiction = $this->engine->jurisdictionFor($organization);

        $drafted = DB::transaction(function () use ($original, $plan, $input): array {
            // Two submissions of one return wait here for each other, so the
            // second sees what the first returned (returned()).
            $locked = Invoice::withoutTenantScope(fn () => Invoice::query()->whereKey($original->id)->with('lines')->lockForUpdate()->firstOrFail());

            match (true) {
                $plan->kind->returnsLines() => $this->returned($locked, (array) ($input['lines'] ?? [])),
                in_array($plan->kind, [AdjustmentKind::Return, AdjustmentKind::BuyerCorrection], true) => $this->assertNothingReturned($locked),
                default => null,
            };

            return array_map(fn (array $document): array => [
                ...$document,
                'invoice' => $this->drafter->draft($document['draft'], (string) $original->org_id, $original->branch_id, $document['countryFields']),
            ], $plan->documents);
        });

        $results = [];

        try {
            foreach ($drafted as $document) {
                $results[] = ['role' => $document['role'], 'invoice' => $document['invoice'], ...($document['issue']
                    ? $this->issue($document['invoice'], $organization, $jurisdiction, $person, $integration)
                    : ['status' => 'draft', 'approval' => null, 'message' => null])];
            }
        } catch (Throwable $e) {
            $this->discard($drafted, $results);

            throw $e;
        }

        $adjustment = InvoiceAdjustment::query()->create([
            'org_id' => (string) $original->org_id,
            'branch_id' => $original->branch_id,
            'invoice_id' => (string) $original->id,
            'kind' => $plan->kind,
            'reason' => $plan->reason === null ? null : mb_substr($plan->reason, 0, 500),
            'details' => $plan->details,
            'note_id' => collect($results)->first(fn (array $result): bool => $result['role'] !== self::INVOICE)['invoice']->id ?? null,
            'new_invoice_id' => collect($results)->first(fn (array $result): bool => $result['role'] === self::INVOICE)['invoice']->id ?? null,
        ]);

        $this->audit->logChange('invoice.adjusted', 'Invoice', (string) $original->id, null, [
            'adjustment_id' => (string) $adjustment->id,
            'kind' => $plan->kind->value,
            'reason' => $plan->reason,
            'details' => $plan->details,
            'documents' => array_map(fn (array $result): array => ['role' => $result['role'], 'invoice_id' => (string) $result['invoice']->id, 'status' => $result['status']], $results),
        ], [], null, (string) $original->org_id);

        return ['adjustment' => $adjustment, 'documents' => $results];
    }

    /**
     * The invoice's adjustments, oldest first, with their documents: its
     * timeline after issue.
     *
     * @return Collection<int, InvoiceAdjustment>
     */
    public function of(Invoice $invoice): Collection
    {
        return InvoiceAdjustment::withoutTenantScope(fn () => InvoiceAdjustment::query()
            ->where('org_id', $invoice->org_id)
            ->where('invoice_id', $invoice->id)
            ->with(['note', 'newInvoice'])
            ->orderBy('created_at')
            ->get());
    }

    /**
     * Issue a document now, or hold it for a second person where the
     * organization holds notes (NoteIssuing): the person's approval request
     * from the portal, a request no person made from an integration.
     *
     * @return array{status: string, approval: array<string, mixed>|null, message: string|null}
     *
     * @throws DomainException when it was refused
     */
    private function issue(Invoice $invoice, Organization $organization, Jurisdiction $jurisdiction, ?User $person, bool $integration): array
    {
        $invoice = $invoice->fresh(['lines']);

        if ($this->notes->holds($invoice, $organization)) {
            if ($integration || $person === null) {
                $held = $this->notes->holdIntegration($invoice, $organization, $person);

                if ($held !== null) {
                    return ['status' => 'pending_approval', 'approval' => $held, 'message' => null];
                }
            } else {
                $outcome = $this->approvals->submit($organization, NoteIssuing::ACTION, $this->notes->payload($invoice), $person, (string) $invoice->adjustment_reason);

                if ($outcome->waits()) {
                    return ['status' => 'pending_approval', 'approval' => [
                        'id' => (string) $outcome->request->id, 'status' => (string) $outcome->request->status, 'expires_at' => $outcome->request->expires_at?->toIso8601String(),
                    ], 'message' => null];
                }

                return ['status' => 'issued', 'approval' => null, 'message' => $outcome->result?->message];
            }
        }

        $outcome = $this->engine->issue($invoice, $organization, new IssueOptions(file: $jurisdiction->transmission()->readyToFile($organization)));

        if (! $outcome->issued) {
            throw new DomainException($outcome->errors[0] ?? __('The note could not be issued.'));
        }

        return ['status' => 'issued', 'approval' => null, 'message' => $outcome->errors[0] ?? null];
    }

    /**
     * Everything drafted for a refused adjustment, discarded. A document
     * already issued stays: it is on the record, and the error says so.
     *
     * @param  list<array<string, mixed>>  $drafted
     * @param  list<array<string, mixed>>  $results
     */
    private function discard(array $drafted, array $results): void
    {
        if (collect($results)->contains(fn (array $result): bool => $result['status'] !== 'draft')) {
            return;
        }

        foreach ($drafted as $document) {
            $invoice = $document['invoice']->fresh();

            if ($invoice !== null && $invoice->isEditable()) {
                $this->drafter->discard($invoice);
            }
        }
    }

    /**
     * The original's lines the request returns, with the quantity of each,
     * checked against what is left of it.
     *
     * @param  array<int, array<string, mixed>>  $requested
     * @return list<array{position: int, line: InvoiceLine, description: string, quantity: string}>
     *
     * @throws ValidationException
     */
    private function returned(Invoice $original, array $requested): array
    {
        $lines = $original->lines->values();
        $already = $this->alreadyReturned($original);
        $returned = [];

        foreach (array_values($requested) as $index => $row) {
            $position = (int) ($row['line'] ?? 0);
            $line = $lines[$position - 1] ?? null;
            $quantity = (string) ($row['quantity'] ?? '0');

            if ($line === null) {
                throw ValidationException::withMessages(["lines.{$index}.line" => __('The invoice has no line :line.', ['line' => $position])]);
            }

            if (bccomp($quantity, '0', 3) <= 0) {
                continue;
            }

            $left = bcsub((string) $line->quantity, $already[$position] ?? '0', 3);

            if (bccomp($quantity, $left, 3) > 0) {
                throw ValidationException::withMessages(["lines.{$index}.quantity" => __('Line :line has :left left to return; :quantity is more than that.', [
                    'line' => $position, 'left' => $this->quantity($left), 'quantity' => $this->quantity($quantity),
                ])]);
            }

            $returned[] = ['position' => $position, 'line' => $line, 'description' => (string) $line->description, 'quantity' => $this->quantity($quantity)];
        }

        if ($returned === []) {
            throw ValidationException::withMessages(['lines' => __('Choose the lines returned, and how many of each.')]);
        }

        return $returned;
    }

    /**
     * What earlier adjustments took back of each line, by position: a full
     * return or a buyer's correction all of it.
     *
     * @return array<int, string>
     */
    private function alreadyReturned(Invoice $original): array
    {
        $taken = [];

        foreach ($this->of($original) as $adjustment) {
            if (in_array($adjustment->kind, [AdjustmentKind::Return, AdjustmentKind::BuyerCorrection], true)) {
                foreach ($original->lines->values() as $index => $line) {
                    $taken[$index + 1] = (string) $line->quantity;
                }

                continue;
            }

            if (! in_array($adjustment->kind, [AdjustmentKind::PartialReturn, AdjustmentKind::Exchange, AdjustmentKind::PriceDifference], true)) {
                continue;
            }

            foreach ((array) ($adjustment->details['lines'] ?? []) as $line) {
                $position = (int) ($line['line'] ?? 0);
                $taken[$position] = bcadd($taken[$position] ?? '0', (string) ($line['quantity'] ?? '0'), 3);
            }
        }

        return $taken;
    }

    /** A full return or a correction credits the whole invoice, so nothing of it may be returned already. */
    private function assertNothingReturned(Invoice $original): void
    {
        if ($this->alreadyReturned($original) !== []) {
            throw ValidationException::withMessages(['kind' => __('Part of this invoice was already returned: return the rest line by line.')]);
        }
    }

    /**
     * The returned quantities at what they were charged: the line's net for
     * that share of its quantity, times the invoice's taxable amount over
     * its lines' net, per unit to the halalah.
     *
     * @param  list<array{position: int, line: InvoiceLine, description: string, quantity: string}>  $returned
     * @return list<array<string, mixed>>
     */
    private function valued(Invoice $original, array $returned): array
    {
        $factor = $this->factor($original);

        return array_map(fn (array $row): array => [
            ...$this->treatment($row['line']),
            'position' => $row['position'],
            'description' => (string) $row['line']->description,
            'description_ar' => $row['line']->description_ar,
            'unit_code' => $row['line']->unit_code,
            'quantity' => $row['quantity'],
            'unit_price' => $this->round(bcmul(bcdiv((string) $row['line']->net_amount, (string) $row['line']->quantity, 8), $factor, 8)),
        ], $returned);
    }

    /**
     * The discount on damaged goods kept, as credit note lines: an amount
     * before VAT shared over the lines chosen in proportion to what each
     * was charged, or a percentage of each.
     *
     * @param  array<string, mixed>  $input
     * @return array{0: list<array<string, mixed>>, 1: array<string, string>}
     *
     * @throws ValidationException
     */
    private function discounted(Invoice $original, array $input): array
    {
        $amount = $input['discount_amount'] ?? null;
        $percent = $input['discount_percent'] ?? null;

        if (($amount === null) === ($percent === null)) {
            throw ValidationException::withMessages(['discount_amount' => __('Give the discount as an amount before VAT, or as a percentage - one of them.')]);
        }

        $chosen = array_filter(array_map(fn ($row): int => (int) ($row['line'] ?? 0), (array) ($input['lines'] ?? [])));
        $factor = $this->factor($original);
        $lines = $original->lines->values()
            ->map(fn (InvoiceLine $line, int $index): array => ['position' => $index + 1, 'line' => $line, 'value' => $this->round(bcmul((string) $line->net_amount, $factor, 8))])
            ->filter(fn (array $row): bool => $chosen === [] || in_array($row['position'], $chosen, true))
            ->values();

        if ($lines->isEmpty()) {
            throw ValidationException::withMessages(['lines' => __('Choose the lines the discount is on.')]);
        }

        $base = $this->sum($lines->map(fn (array $row): array => ['quantity' => '1', 'unit_price' => $row['value']])->all());

        if ($amount !== null && bccomp((string) $amount, $base, 2) > 0) {
            throw ValidationException::withMessages(['discount_amount' => __('The discount is at most what those lines were charged before VAT, :total.', ['total' => $base])]);
        }

        $values = $amount !== null
            ? ShareAllocation::apportion((string) $amount, $lines->pluck('value')->all())
            : $lines->map(fn (array $row): string => $this->round(bcdiv(bcmul($row['value'], (string) $percent, 8), '100', 8)))->all();

        $credit = [];

        foreach ($lines as $index => $row) {
            if (bccomp($values[$index], '0', 2) > 0) {
                $credit[] = [
                    ...$this->treatment($row['line']),
                    'position' => $row['position'],
                    'description' => (string) $row['line']->description,
                    'description_ar' => $row['line']->description_ar,
                    'unit_code' => null,
                    'quantity' => '1',
                    'unit_price' => $values[$index],
                ];
            }
        }

        return [$credit, array_filter(['amount' => $amount === null ? null : (string) $amount, 'percent' => $percent === null ? null : (string) $percent])];
    }

    /**
     * The items given in place of what was returned, taxed as the first
     * line returned was unless they say otherwise.
     *
     * @param  array<string, mixed>  $input
     * @param  array{line: InvoiceLine}  $first
     * @return list<array<string, mixed>>
     *
     * @throws ValidationException
     */
    private function items(array $input, array $first): array
    {
        $items = array_values((array) ($input['items'] ?? []));

        if ($items === []) {
            throw ValidationException::withMessages(['items' => __('Give the items the customer takes instead.')]);
        }

        $treatment = $this->treatment($first['line']);

        return array_map(fn (array $item): array => [
            ...$treatment,
            ...array_filter(array_intersect_key($item, array_flip(['tax_category', 'tax_rate', 'exempt_code', 'exempt_reason'])), fn ($value): bool => $value !== null && $value !== ''),
            'position' => null,
            'description' => (string) $item['description'],
            'description_ar' => $item['description_ar'] ?? null,
            'unit_code' => null,
            'quantity' => $this->quantity((string) $item['quantity']),
            'unit_price' => bcadd((string) $item['unit_price'], '0', 2),
        ], $items);
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, string>>
     */
    private function itemDetails(array $items): array
    {
        return array_map(fn (array $item): array => ['description' => $item['description'], 'quantity' => $item['quantity'], 'unit_price' => $item['unit_price']], $items);
    }

    /**
     * The one line of a price difference: the new item for the returned
     * one, at the difference, taxed as the returned one was.
     *
     * @param  list<array{position: int, line: InvoiceLine, description: string, quantity: string}>  $returned
     * @param  list<array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    private function differenceLine(array $returned, array $items, string $difference): array
    {
        $old = implode(', ', array_map(fn (array $row): string => $row['description'], $returned));
        $new = implode(', ', array_map(fn (array $item): string => $item['description'], $items));
        $oldAr = implode('، ', array_map(fn (array $row): string => (string) ($row['line']->description_ar ?: $row['description']), $returned));
        $newAr = implode('، ', array_map(fn (array $item): string => (string) ($item['description_ar'] ?: $item['description']), $items));

        return [
            ...$this->treatment($returned[0]['line']),
            'position' => null,
            'description' => mb_substr("Price difference: {$new} replacing {$old}", 0, 255),
            'description_ar' => mb_substr("فرق السعر: {$newAr} بدلاً من {$oldAr}", 0, 255),
            'unit_code' => null,
            'quantity' => '1',
            'unit_price' => $difference,
        ];
    }

    /**
     * A line's VAT treatment, to go with what is credited of it.
     *
     * @return array{tax_category: string, tax_rate: string, exempt_code: string|null, exempt_reason: string|null}
     */
    private function treatment(InvoiceLine $line): array
    {
        return [
            'tax_category' => (string) $line->tax_category,
            'tax_rate' => (string) (float) $line->tax_rate,
            'exempt_code' => $line->exempt_code,
            'exempt_reason' => $line->exempt_reason,
        ];
    }

    /** The invoice's taxable amount over its lines' net: 1 with no document discount or charge. */
    private function factor(Invoice $original): string
    {
        $net = $original->lines->reduce(fn (string $sum, InvoiceLine $line): string => bcadd($sum, (string) $line->net_amount, 2), '0');
        $taxable = bcsub((string) $original->total, (string) $original->tax_amount, 2);

        return bccomp($net, '0', 2) <= 0 ? '1' : bcdiv($taxable, $net, 8);
    }

    /**
     * What lines come to before VAT.
     *
     * @param  list<array<string, mixed>>  $lines
     */
    private function sum(array $lines): string
    {
        return array_reduce($lines, fn (string $sum, array $line): string => bcadd($sum, $this->round(bcmul((string) $line['quantity'], (string) $line['unit_price'], 8)), 2), '0.00');
    }

    /** Half up, to the halalah. */
    private function round(string $value): string
    {
        return bcadd($value, bccomp($value, '0', 8) < 0 ? '-0.005' : '0.005', 2);
    }

    /** A quantity without trailing zeros. */
    private function quantity(string $value): string
    {
        $value = bcadd($value, '0', 3);

        return str_contains($value, '.') ? rtrim(rtrim($value, '0'), '.') : $value;
    }
}
