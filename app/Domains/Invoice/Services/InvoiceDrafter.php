<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Services;

use App\Domains\Audit\Services\AuditService;
use App\Domains\Invoice\Enums\InvoiceStatus;
use App\Domains\Invoice\Exceptions\InvoiceNotEditableException;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Invoice\Models\InvoiceCharge;
use App\Domains\Invoice\Models\InvoicePrepayment;
use App\Domains\Invoice\Support\UnitCode;
use App\Domains\Organization\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates, revises and discards draft invoices.
 *
 * The API and the ERP pipeline both create invoices through this, so there is
 * one set of totals for ZATCA to reconcile. The arithmetic is InvoiceTotals'.
 *
 * Every change to a draft is written together with its audit entry: an edit
 * with no record of it, or a record of an edit that did not happen, is what an
 * auditor cannot accept.
 */
class InvoiceDrafter
{
    /** UNCL 7161's "Miscellaneous", a document charge's reason code when it names none. */
    public const CHARGE_REASON = 'ABK';

    public function __construct(
        private readonly AuditService $audit,
        private readonly PrepaymentSettlement $settlement,
        private readonly Catalogue $catalogue,
    ) {}

    /**
     * Create the invoice and its lines as one atomic unit.
     *
     * The transaction also holds the ICV allocation lock taken by
     * Invoice::creating until after the insert commits — without it the
     * counter's lock is released early and two concurrent requests can
     * allocate the same value.
     *
     * @param  string|null  $branchId  EGS unit issuing this invoice. Already
     *                                 confirmed to belong to the organization;
     *                                 decides which certificate signs it.
     */
    /**
     * The invoice an earlier request created under this Idempotency-Key, if
     * any. Outside the tenant scope because the organization is given.
     */
    public function createdUnder(string $organizationId, string $idempotencyKey): ?Invoice
    {
        return Invoice::withoutTenantScope(fn (): ?Invoice => Invoice::query()
            ->where('org_id', $organizationId)
            ->where('idempotency_key', $idempotencyKey)
            ->first());
    }

    /**
     * The invoice an earlier request drafted for this ERP document, if any.
     */
    public function createdFor(string $organizationId, string $erpReferenceId): ?Invoice
    {
        return Invoice::withoutTenantScope(fn (): ?Invoice => Invoice::query()
            ->where('org_id', $organizationId)
            ->where('erp_reference_id', $erpReferenceId)
            ->oldest()
            ->first());
    }

    /**
     * $countryFields are header columns a country decides for itself - the
     * due_date, a UAE invoice's jurisdiction_data and profile_id, a Saudi
     * invoice's transaction types. They are
     * taken as given and apart from $data, so a caller names them on purpose
     * and nothing a Saudi request validated can reach them.
     *
     * $tillId is the till the request named, already checked to be one of
     * the organization's that issues (Tills::resolve): the draft keeps it, and
     * is issued on it. Null leaves the till to be chosen at issue.
     *
     * @param  array<string, mixed>  $countryFields
     */
    public function draft(array $data, string $organizationId, ?string $branchId = null, array $countryFields = [], ?string $tillId = null): Invoice
    {
        $this->assertSupplyPeriod($data['supply_date'] ?? null, $data['supply_end_date'] ?? null);

        // The invoice says whether its prices include VAT; if it does not,
        // the organization's setting decides - its own, or the platform's.
        $inclusive = array_key_exists('prices_include_vat', $data) && $data['prices_include_vat'] !== null
            ? (bool) $data['prices_include_vat']
            : (bool) Organization::query()->whereKey($organizationId)->first()?->prices_include_vat;

        $totals = InvoiceTotals::of($data['lines'], (string) ($data['discount_amount'] ?? '0'), $inclusive, array_values((array) ($data['charges'] ?? [])));

        $prepayments = $this->prepayments($data, $organizationId, $totals->total);

        return DB::transaction(function () use ($data, $organizationId, $branchId, $totals, $inclusive, $countryFields, $prepayments, $tillId) {
            $invoice = Invoice::create([
                ...$countryFields,
                'org_id' => $organizationId,
                'branch_id' => $branchId,
                'device_id' => $tillId,
                'invoice_number' => $data['invoice_number'] ?? null,
                'type' => $data['type'],
                'document_type' => $data['document_type'],
                'status' => InvoiceStatus::Draft,
                'issue_date' => $data['issue_date'],
                'supply_date' => $data['supply_date'] ?? null,
                'supply_end_date' => $data['supply_end_date'] ?? null,
                'currency' => $data['currency'] ?? 'SAR',
                'exchange_rate' => $data['exchange_rate'] ?? null,
                'payment_means_code' => $data['payment_means_code'] ?? '10',
                'payment_terms' => $data['payment_terms'] ?? null,
                'buyer_name' => $data['buyer_name'] ?? null,
                'buyer_name_ar' => $data['buyer_name_ar'] ?? null,
                'buyer_vat_number' => $data['buyer_vat_number'] ?? null,
                'buyer_id' => $data['buyer_id'] ?? null,
                'buyer_id_scheme' => $data['buyer_id_scheme'] ?? null,
                'buyer_address' => $data['buyer_address'] ?? null,
                'billing_ref' => $data['billing_ref'] ?? null,
                'order_reference' => $data['order_reference'] ?? null,
                'contract_reference' => $data['contract_reference'] ?? null,
                'buyer_reference' => $data['buyer_reference'] ?? null,
                'adjustment_reason' => $data['adjustment_reason'] ?? null,
                'notes' => $data['notes'] ?? null,
                'erp_reference_id' => $data['erp_reference_id'] ?? null,
                'idempotency_key' => $data['idempotency_key'] ?? null,
                // When the till made the sale - the actual supply, which the
                // daily close counts by. Not the moment of issue, which
                // IssuingLifecycle sets when the final invoice is generated.
                // An instant, so held in UTC whatever offset it was sent with.
                'sold_at' => filled($data['sold_at'] ?? null) ? CarbonImmutable::parse($data['sold_at'])->utc() : null,
                'prices_include_vat' => $inclusive,
            ]);

            foreach (array_values($data['lines']) as $index => $line) {
                $priced = $totals->lines[$index];

                $created = $invoice->lines()->create([
                    'description' => $line['description'],
                    'description_ar' => $line['description_ar'] ?? null,
                    // PINT AE's item name; no Saudi request carries one.
                    'item_name' => $line['item_name'] ?? null,
                    'class_code' => $line['class_code'] ?? null,
                    'quantity' => (string) $line['quantity'],
                    'unit_code' => UnitCode::normalise($line['unit_code'] ?? null),
                    // Net, with the exact net beside it: a price entered with
                    // VAT in it is stored as the net it contains.
                    'unit_price' => $priced['unit_price'],
                    // As entered, and already off the net.
                    'discount_amount' => bccomp($priced['discount'], '0', 2) > 0 ? $priced['discount'] : null,
                    'net_amount' => $priced['net'],
                    'tax_rate' => $priced['rate'],
                    'tax_amount' => $priced['tax'],
                    'tax_category' => $line['tax_category'] ?? 'S',
                    'exempt_code' => $line['exempt_code'] ?? null,
                    'exempt_reason' => $line['exempt_reason'] ?? null,
                    'line_total' => $priced['total'],
                    // The saved product it was made from, so that product is
                    // known to be used (CatalogueRemoval).
                    'product_id' => $this->catalogue->productNamed(
                        $organizationId,
                        is_string($line['product_id'] ?? null) ? $line['product_id'] : null,
                        is_string($line['product_code'] ?? null) ? $line['product_code'] : null,
                    )?->id,
                ]);

                // Taxed as the line is; on an invoice priced with VAT in it
                // its net is in the line's.
                foreach (array_values((array) ($line['charges'] ?? [])) as $position => $charge) {
                    $resolved = $priced['charges'][$position];
                    $this->addCharge($invoice, $charge, $resolved, $position, $created->id, $inclusive ? null : $resolved['amount']);
                }
            }

            foreach (array_values((array) ($data['charges'] ?? [])) as $position => $charge) {
                $resolved = $totals->charges[$position];
                $this->addCharge($invoice, $charge, $resolved, $position, null, $resolved['net'], [
                    'tax_category' => $resolved['category'],
                    'tax_rate' => $resolved['rate'],
                    'exempt_code' => $charge['exempt_code'] ?? null,
                    'exempt_reason' => $charge['exempt_reason'] ?? null,
                ]);
            }

            $prepaid = '0.00';

            foreach ($prepayments as $position => $row) {
                InvoicePrepayment::query()->create(['invoice_id' => $invoice->id, 'position' => $position, ...$row]);
                $prepaid = bcadd($prepaid, bcadd($row['taxable_amount'], $row['tax_amount'], 2), 2);
            }

            $invoice->update([
                'subtotal' => $totals->subtotal,
                'discount_amount' => $totals->discount,
                'tax_amount' => $totals->tax,
                'total' => $totals->total,
                ...($prepayments === [] ? [] : ['prepaid_amount' => $prepaid]),
            ]);

            $this->audit->logCreated($invoice);

            return $invoice;
        });
    }

    /**
     * Change a draft's header fields and record the change.
     *
     * @param  array<string, mixed>  $changes  Header columns to set
     *
     * @throws InvoiceNotEditableException when the invoice is no longer a draft
     */
    public function revise(Invoice $invoice, array $changes): Invoice
    {
        return DB::transaction(function () use ($invoice, $changes): Invoice {
            $draft = $this->lockDraft($invoice);
            $oldValues = $draft->toArray();

            $draft->update($changes);
            $this->audit->logUpdated($draft, $oldValues);

            return $draft;
        });
    }

    /**
     * Delete a draft and record its deletion.
     *
     * @throws InvoiceNotEditableException when the invoice is no longer a draft
     */
    public function discard(Invoice $invoice): void
    {
        DB::transaction(function () use ($invoice): void {
            $draft = $this->lockDraft($invoice);

            // Deleted outright, not soft-deleted: a draft has no number, no
            // counter and no hash, so it is not yet an invoice ZATCA forbids
            // deleting (Detailed Guidelines 5.4 p26, 5.6 p27), and a removed
            // row in invoices would sit beside every issued one in every
            // query that must never miss one. It is not deleted undetected:
            // the audit entry keeps all of it, lines and charges included.
            $this->audit->logDeleted($draft->load(['lines', 'charges', 'prepayments']));
            $draft->delete();
        });
    }

    /**
     * The prepayment invoices this one settles, by number: what each
     * prepaid per tax category, as it said. Refused before anything is
     * written when one cannot be settled, or when together they come to
     * more than this invoice, which would leave a negative amount to pay.
     *
     * @param  array<string, mixed>  $data
     * @return list<array<string, mixed>>
     *
     * @throws ValidationException
     */
    private function prepayments(array $data, string $organizationId, string $total): array
    {
        $rows = [];
        $prepaid = '0';

        foreach (array_values((array) ($data['prepayments'] ?? [])) as $index => $reference) {
            $prepayment = $this->settlement->settleable($organizationId, (string) ($reference['invoice_number'] ?? ''), (string) ($data['currency'] ?? 'SAR'));

            if (is_string($prepayment)) {
                throw ValidationException::withMessages(["prepayments.{$index}.invoice_number" => $prepayment]);
            }

            foreach ($this->settlement->prepaid($prepayment) as $category) {
                $rows[] = [
                    'prepayment_invoice_id' => $prepayment->id,
                    'invoice_number' => (string) $prepayment->invoice_number,
                    'issue_date' => $prepayment->issue_date->toDateString(),
                    'issued_at' => $prepayment->issuedMoment(),
                    ...$category,
                ];
                $prepaid = bcadd($prepaid, bcadd($category['taxable_amount'], $category['tax_amount'], 2), 2);
            }
        }

        if (bccomp($prepaid, $total, 2) > 0) {
            throw ValidationException::withMessages(['prepayments' => __('The prepayments come to more than this invoice.')]);
        }

        return $rows;
    }

    /**
     * Keep a charge as InvoiceTotals priced it.
     *
     * @param  array<string, mixed>  $charge  As the request gave it
     * @param  array{amount: string, percent: ?string, base: ?string}  $resolved
     * @param  string|null  $net  What the document states; null for a line
     *                            charge folded into its line's net
     * @param  array<string, mixed>  $tax  A document charge's category, rate and exemption
     */
    private function addCharge(Invoice $invoice, array $charge, array $resolved, int $position, ?string $lineId, ?string $net, array $tax = []): void
    {
        InvoiceCharge::query()->create([
            'invoice_id' => $invoice->id,
            'invoice_line_id' => $lineId,
            'position' => $position,
            'reason' => (string) $charge['reason'],
            'reason_code' => (string) ($charge['reason_code'] ?? self::CHARGE_REASON),
            'percent' => $resolved['percent'],
            'base_amount' => $resolved['base'],
            'amount' => $resolved['amount'],
            'net_amount' => $net ?? '0.00',
            ...$tax,
        ]);
    }

    /**
     * A supply period has to start, and cannot end before it does.
     *
     * BR-KSA-35 and BR-KSA-36. Checked before anything is written, so a
     * period ZATCA would reject never becomes a draft that cannot be issued.
     *
     * @throws ValidationException
     */
    private function assertSupplyPeriod(mixed $start, mixed $end): void
    {
        if ($end === null || $end === '') {
            return;
        }

        if ($start === null || $start === '') {
            throw ValidationException::withMessages([
                'supply_end_date' => __('A supply end date needs a supply date.'),
            ]);
        }

        if (CarbonImmutable::parse($end)->startOfDay()->lt(CarbonImmutable::parse($start)->startOfDay())) {
            throw ValidationException::withMessages([
                'supply_end_date' => __('The supply end date cannot be before the supply date.'),
            ]);
        }
    }

    /**
     * Re-read the invoice under lock and confirm it is still a draft.
     *
     * Issuance could otherwise land between the check and the write, and the
     * edit would change a document that had already been signed.
     */
    private function lockDraft(Invoice $invoice): Invoice
    {
        $locked = Invoice::query()
            ->whereKey($invoice->getKey())
            ->lockForUpdate()
            ->firstOrFail();

        if (! $locked->isEditable()) {
            throw InvoiceNotEditableException::for($locked);
        }

        return $locked;
    }
}
