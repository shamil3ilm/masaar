<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Services;

use App\Domains\Compliance\Engine\Services\JurisdictionResolver;
use App\Domains\Invoice\Enums\DocumentType;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Invoice\Models\InvoiceCharge;
use App\Domains\Invoice\Models\InvoiceLine;
use App\Domains\Organization\Models\Organization;
use DomainException;
use Illuminate\Database\Eloquent\Collection;

/**
 * A schedule started from an invoice the organization already wrote: the
 * same buyer, lines, charges and terms, as a request to write it again
 * would give them - the shared columns here, the country's own through
 * RecurringSupply::fromInvoice. The schedule then checks them by the
 * country's rules, as for any schedule.
 */
class ScheduleSources
{
    public function __construct(
        private readonly InvoiceFinder $invoices,
        private readonly JurisdictionResolver $jurisdictions,
    ) {}

    /**
     * The organization's latest invoices, to choose the one a schedule
     * repeats: invoices only, never a note or an advance.
     *
     * @return Collection<int, Invoice>
     */
    public function choices(string $organizationId, int $limit = 50): Collection
    {
        return Invoice::query()
            ->where('org_id', $organizationId)
            ->where('document_type', DocumentType::Invoice->value)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get(['id', 'invoice_number', 'buyer_name', 'issue_date', 'total', 'currency', 'status']);
    }

    /**
     * The invoice, and what the schedule takes from it beside: its branch,
     * its customer and its days to pay.
     *
     * @return array{invoice: Invoice, template: array<string, mixed>, attributes: array<string, mixed>}
     *
     * @throws DomainException for a note or an advance, which do not repeat
     */
    public function fromInvoice(Organization $organization, string $invoiceId): array
    {
        $invoice = $this->invoices->findWithLines((string) $organization->id, $invoiceId)->load(['lines.charges', 'charges']);

        if ($invoice->document_type !== DocumentType::Invoice) {
            throw new DomainException(__('Only an invoice is repeated on a schedule, not a note or a prepayment.'));
        }

        $inclusive = (bool) $invoice->prices_include_vat;
        $country = $this->jurisdictions->forRules($organization)->recurringSupply()->fromInvoice($invoice);
        $extra = (array) ($country['lines'] ?? []);
        unset($country['lines']);

        $template = array_filter([
            'type' => $invoice->type->value,
            'document_type' => DocumentType::Invoice->value,
            'currency' => (string) $invoice->currency,
            'exchange_rate' => $invoice->exchange_rate === null ? null : (string) (float) $invoice->exchange_rate,
            'prices_include_vat' => $inclusive,
            'payment_means_code' => $invoice->payment_means_code,
            'payment_terms' => $invoice->payment_terms,
            'buyer_name' => $invoice->buyer_name,
            'buyer_name_ar' => $invoice->buyer_name_ar,
            'buyer_vat_number' => $invoice->buyer_vat_number,
            'buyer_id' => $invoice->buyer_id,
            'buyer_id_scheme' => $invoice->buyer_id_scheme,
            'buyer_address' => $invoice->buyer_address,
            'order_reference' => $invoice->order_reference,
            'contract_reference' => $invoice->contract_reference,
            'buyer_reference' => $invoice->buyer_reference,
            'notes' => $invoice->notes,
            ...array_filter($invoice->only(['is_third_party', 'is_nominal', 'is_export', 'is_summary', 'is_self_billed', 'is_government'])),
            'discount_amount' => bccomp((string) $invoice->discount_amount, '0', 2) > 0 ? (string) $invoice->discount_amount : null,
            'lines' => $invoice->lines->values()->map(fn (InvoiceLine $line, int $index): array => [
                ...$this->line($line, $inclusive),
                ...(array) ($extra[$index] ?? []),
            ])->all(),
            'charges' => $invoice->charges->map(fn (InvoiceCharge $charge): array => [
                ...$this->charge($charge),
                'tax_category' => (string) $charge->tax_category,
                'tax_rate' => (string) (float) $charge->tax_rate,
                'exempt_code' => $charge->exempt_code,
                'exempt_reason' => $charge->exempt_reason,
            ])->values()->all(),
            ...$country,
        ], fn (mixed $value): bool => $value !== null && $value !== '' && $value !== []);

        return [
            'invoice' => $invoice,
            'template' => $template,
            'attributes' => [
                'branch_id' => $invoice->branch_id,
                'customer_id' => $invoice->customer_id,
                'source_invoice_id' => (string) $invoice->id,
                'due_days' => $invoice->due_date === null ? null : max(0, (int) $invoice->issue_date->diffInDays($invoice->due_date)),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function line(InvoiceLine $line, bool $inclusive): array
    {
        return array_filter([
            'product_id' => $line->product_id,
            'description' => $line->description,
            'description_ar' => $line->description_ar,
            'item_name' => $line->item_name,
            'class_code' => $line->class_code,
            'quantity' => rtrim(rtrim((string) $line->quantity, '0'), '.'),
            'unit_price' => $line->priceAsEntered($inclusive),
            'unit_code' => $line->unit_code,
            'tax_rate' => (string) (float) $line->tax_rate,
            'tax_category' => $line->tax_category,
            'exempt_code' => $line->exempt_code,
            'exempt_reason' => $line->exempt_reason,
            'discount_amount' => $line->discount_amount === null ? null : (string) $line->discount_amount,
            'charges' => $line->charges->map(fn (InvoiceCharge $charge): array => $this->charge($charge))->values()->all(),
        ], fn (mixed $value): bool => $value !== null && $value !== '' && $value !== []);
    }

    /**
     * A charge as a request gives it: its percentage and base when it was
     * one, or its amount.
     *
     * @return array<string, string>
     */
    private function charge(InvoiceCharge $charge): array
    {
        return [
            'reason' => (string) $charge->reason,
            'reason_code' => (string) $charge->reason_code,
            ...($charge->percent !== null
                ? ['percent' => (string) (float) $charge->percent, 'base_amount' => (string) $charge->base_amount]
                : ['amount' => (string) $charge->amount]),
        ];
    }
}
