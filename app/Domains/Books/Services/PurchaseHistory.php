<?php

declare(strict_types=1);

namespace App\Domains\Books\Services;

use App\Domains\Books\Enums\PurchaseTreatment;
use App\Domains\Books\Models\PurchaseInvoice;
use App\Http\Listing\CsvExport;
use App\Http\Listing\Filters\Choice;
use App\Http\Listing\Filters\DateRange;
use App\Http\Listing\Filters\NumberRange;
use App\Http\Listing\Filters\Search;
use App\Http\Listing\ListSpec;
use App\Http\Listing\ListState;
use App\Http\Listing\Sort;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * An organization's purchases as a history list: the same filters, order,
 * saved views and CSV every other list has. Every query names the
 * organization.
 */
class PurchaseHistory
{
    public function __construct(
        private readonly Suppliers $suppliers,
    ) {}

    public function spec(string $organizationId, string $key): ListSpec
    {
        $suppliers = $this->suppliers->all($organizationId)->mapWithKeys(fn ($supplier): array => [(string) $supplier->id => (string) $supplier->name])->all();

        return new ListSpec(
            key: $key,
            sorts: [
                'date' => new Sort(__('Date'), 'invoice_date'),
                'number' => new Sort(__('Number'), 'supplier_invoice_number', 'asc'),
                'total' => new Sort(__('Total'), 'gross_tax'),
                'recorded' => new Sort(__('Recorded'), 'created_at'),
            ],
            defaultSort: 'date',
            filters: [
                new Search('q', __('Search'), ['supplier_invoice_number', 'description'], __('Number or description')),
                new Choice('supplier', __('Supplier'), $suppliers, 'supplier_id'),
                new Choice('type', __('Document'), [PurchaseInvoice::INVOICE => __('Invoice'), PurchaseInvoice::CREDIT_NOTE => __('Credit note')], 'document_type'),
                new Choice('treatment', __('VAT treatment'), PurchaseTreatment::options(),
                    fn (Builder $query, string $value) => $query->whereHas('lines', fn (Builder $lines) => $lines->where('treatment', $value))),
                new Choice('status', __('Status'), [PurchaseInvoice::RECORDED => __('Recorded'), PurchaseInvoice::VOID => __('Void')], 'status'),
                new Choice('capital', __('Capital assets'), ['1' => __('With a capital asset')],
                    fn (Builder $query) => $query->whereHas('lines', fn (Builder $lines) => $lines->where('is_capital_asset', true))),
                new DateRange('invoice_date', __('Document date')),
                new NumberRange('gross_tax', __('Total')),
            ],
        );
    }

    public function page(string $organizationId, ListState $list): LengthAwarePaginator
    {
        return $list->paginate($this->query($organizationId));
    }

    public function csv(string $organizationId, ListState $list, string $fileName): StreamedResponse
    {
        $money = fn (string $column): \Closure => fn (PurchaseInvoice $purchase): string => number_format($purchase->sign() * (float) $purchase->{$column}, 2, '.', '');

        return CsvExport::download($fileName, [
            __('Date') => fn (PurchaseInvoice $purchase): string => $purchase->invoice_date->toDateString(),
            __('Supplier') => fn (PurchaseInvoice $purchase): string => (string) $purchase->supplier?->name,
            __('Supplier VAT number') => fn (PurchaseInvoice $purchase): string => (string) $purchase->supplier?->vat_number,
            __('Document') => fn (PurchaseInvoice $purchase): string => $purchase->document_type,
            __('Number') => fn (PurchaseInvoice $purchase): string => $purchase->supplier_invoice_number,
            __('Currency') => fn (PurchaseInvoice $purchase): string => $purchase->currency,
            __('Exchange rate') => fn (PurchaseInvoice $purchase): string => (string) $purchase->exchange_rate,
            __('Net') => $money('net_tax'),
            __('VAT') => $money('vat_tax'),
            __('Total') => $money('gross_tax'),
            __('Deductible VAT') => $money('deductible_vat_tax'),
            __('Deductible share (%)') => fn (PurchaseInvoice $purchase): string => (string) $purchase->deductible_share,
            __('Treatments') => fn (PurchaseInvoice $purchase): string => $purchase->lines->pluck('treatment')->map(fn ($treatment): string => $treatment->value)->unique()->implode(' '),
            __('Status') => fn (PurchaseInvoice $purchase): string => $purchase->status,
        ], $list->apply($this->query($organizationId))->lazy(500));
    }

    /** @return Builder<PurchaseInvoice> */
    private function query(string $organizationId): Builder
    {
        return PurchaseInvoice::query()->where('org_id', $organizationId)->with(['supplier', 'lines']);
    }
}
