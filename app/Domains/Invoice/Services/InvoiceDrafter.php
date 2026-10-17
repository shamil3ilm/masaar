<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Services;

use App\Domains\Audit\Services\AuditService;
use App\Domains\Invoice\Enums\InvoiceStatus;
use App\Domains\Invoice\Exceptions\InvoiceNotEditableException;
use App\Domains\Invoice\Models\Invoice;
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
    public function __construct(
        private readonly AuditService $audit,
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
     * $countryFields are header columns only one country's documents carry -
     * a UAE invoice's due_date, jurisdiction_data and profile_id. They are
     * taken as given and apart from $data, so a caller names them on purpose
     * and nothing a Saudi request validated can reach them.
     *
     * @param  array<string, mixed>  $countryFields
     */
    public function draft(array $data, string $organizationId, ?string $branchId = null, array $countryFields = []): Invoice
    {
        $this->assertSupplyPeriod($data['supply_date'] ?? null, $data['supply_end_date'] ?? null);

        // The invoice says whether its prices include VAT; if it does not,
        // the organization's setting decides.
        $inclusive = array_key_exists('prices_include_vat', $data) && $data['prices_include_vat'] !== null
            ? (bool) $data['prices_include_vat']
            : (bool) Organization::query()->whereKey($organizationId)->value('prices_include_vat');

        $totals = InvoiceTotals::of($data['lines'], (string) ($data['discount_amount'] ?? '0'), $inclusive);

        return DB::transaction(function () use ($data, $organizationId, $branchId, $totals, $inclusive, $countryFields) {
            $invoice = Invoice::create([
                ...$countryFields,
                'org_id' => $organizationId,
                'branch_id' => $branchId,
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
                'prices_include_vat' => $inclusive,
            ]);

            foreach (array_values($data['lines']) as $index => $line) {
                $priced = $totals->lines[$index];

                $invoice->lines()->create([
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
                    'net_amount' => $priced['net'],
                    'tax_rate' => $priced['rate'],
                    'tax_amount' => $priced['tax'],
                    'tax_category' => $line['tax_category'] ?? 'S',
                    'exempt_code' => $line['exempt_code'] ?? null,
                    'exempt_reason' => $line['exempt_reason'] ?? null,
                    'line_total' => $priced['total'],
                ]);
            }

            $invoice->update([
                'subtotal' => $totals->subtotal,
                'discount_amount' => $totals->discount,
                'tax_amount' => $totals->tax,
                'total' => $totals->total,
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

            $this->audit->logDeleted($draft);
            $draft->delete();
        });
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
