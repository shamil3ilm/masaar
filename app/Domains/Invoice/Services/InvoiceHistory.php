<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Services;

use App\Domains\Compliance\Engine\Services\JurisdictionResolver;
use App\Domains\Invoice\Enums\DocumentType;
use App\Domains\Invoice\Enums\InvoiceStatus;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;
use App\Domains\Organization\Services\BranchScope;
use App\Domains\Organization\Services\BranchService;
use App\Domains\Organization\Services\Membership;
use App\Http\Listing\CsvExport;
use App\Http\Listing\Filters\Choice;
use App\Http\Listing\Filters\DateRange;
use App\Http\Listing\Filters\NumberRange;
use App\Http\Listing\Filters\Search;
use App\Http\Listing\ListSpec;
use App\Http\Listing\ListState;
use App\Http\Listing\Sort;
use App\Support\CountryClock;
use Illuminate\Contracts\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * An organization's invoices, and its credit and debit notes, as history
 * lists: what they sort and narrow by, a page of them, and the CSV.
 *
 * Every query names the organization as well as passing through
 * BelongsToTenant's scope, as InvoiceFinder's do, so a filter can never
 * reach another tenant's rows - and every option a filter offers is the
 * organization's own (its branches, its people, its currencies), so an id
 * of anyone else's in a URL narrows nothing.
 *
 * Which invoice types and document types there are is the organization's
 * country's (InvoiceRules), so the list names no country.
 */
class InvoiceHistory
{
    /** The documents the notes list holds. */
    public const NOTES = [DocumentType::CreditNote, DocumentType::DebitNote];

    public function __construct(
        private readonly JurisdictionResolver $jurisdictions,
        private readonly BranchService $branches,
        private readonly BranchScope $scope,
        private readonly Membership $membership,
    ) {}

    /**
     * @param  string  $key  the list's route name
     * @param  bool  $notes  the credit and debit notes only
     */
    public function spec(Organization $organization, string $key, bool $notes = false): ListSpec
    {
        $organizationId = (string) $organization->id;
        $rules = $this->jurisdictions->forRules($organization)->invoiceRules();
        // Only the branches the person may see are offered: a cashier's list
        // narrows within their own.
        $allowed = $this->scope->allowed();
        $tills = array_values(array_filter(
            $this->branches->tills($organizationId),
            fn (array $till): bool => $allowed === null || in_array($till['id'], $allowed, true),
        ));
        $branches = array_column($tills, 'name', 'id');

        $documents = array_intersect_key(self::documentLabels(), array_flip($rules->documentTypes()));

        if ($notes) {
            $documents = array_intersect_key($documents, array_flip(array_map(fn (DocumentType $type): string => $type->value, self::NOTES)));
        }

        $filters = [
            new Search('q', __('Search'), ['invoice_number', 'buyer_name'], __('Invoice number or buyer')),
            new DateRange('issue_date', __('Issue date'), CountryClock::timeZone($organization->country)),
            new NumberRange('total', __('Total')),
            new Search('buyer', __('Buyer'), ['buyer_name', 'buyer_name_ar', 'buyer_vat_number'], __('Name or VAT number')),
            new Choice('status', __('Status'), self::statusLabels(), 'status'),
        ];

        $types = array_intersect_key(self::typeLabels(), array_flip($rules->invoiceTypes()));

        if (count($types) > 1) {
            $filters[] = new Choice('type', __('Invoice type'), $types, 'type');
        }

        if (count($documents) > 1) {
            $filters[] = new Choice('document', __('Document type'), $documents, 'document_type');
        }

        if ($branches !== []) {
            $filters[] = new Choice('branch', __('Branch'), $branches, 'branch_id');
            $filters[] = new Choice('till', __('Till or device'), $this->devices($organizationId, $tills, $allowed === null), 'device_id');
        }

        $currencies = $this->currencies($organizationId);

        if (count($currencies) > 1) {
            $filters[] = new Choice('currency', __('Currency'), array_combine($currencies, $currencies), 'currency');
        }

        $people = $this->membership->members($organizationId)->mapWithKeys(fn ($user): array => [(string) $user->id => (string) ($user->name ?: $user->email)])->all();
        $filters[] = new Choice('issued_by', __('Issued by'), $people, 'issued_by');

        return new ListSpec(
            key: $key,
            sorts: [
                'newest' => new Sort(__('Newest'), 'created_at'),
                'date' => new Sort(__('Issue date'), 'issue_date'),
                'number' => new Sort(__('Number'), 'invoice_number', 'asc'),
                'amount' => new Sort(__('Amount'), 'total'),
                'buyer' => new Sort(__('Buyer'), 'buyer_name', 'asc'),
                'status' => new Sort(__('Status'), 'status', 'asc'),
            ],
            defaultSort: 'newest',
            filters: $filters,
        );
    }

    public function page(string $organizationId, ListState $list, bool $notes = false): LengthAwarePaginator
    {
        return $list->paginate($this->query($organizationId, $notes)->with('branch:id,name'));
    }

    /**
     * Every invoice the list's filters give, in its order, as a CSV.
     */
    public function csv(Organization $organization, ListState $list, string $fileName, bool $notes = false): StreamedResponse
    {
        $organizationId = (string) $organization->id;
        $people = $this->membership->members($organizationId)->mapWithKeys(fn ($user): array => [(string) $user->id => (string) ($user->name ?: $user->email)])->all();
        $documents = self::documentLabels();
        $statuses = self::statusLabels();
        $value = fn (mixed $enum): string => $enum instanceof \BackedEnum ? (string) $enum->value : (string) $enum;
        $money = fn (mixed $amount): string => number_format((float) $amount, 2, '.', '');

        return CsvExport::download($fileName, [
            __('Number') => fn (Invoice $invoice): string => (string) $invoice->invoice_number,
            __('Document type') => fn (Invoice $invoice): string => $documents[$value($invoice->document_type)] ?? $value($invoice->document_type),
            __('Invoice type') => fn (Invoice $invoice): string => $value($invoice->type),
            __('Issue date') => fn (Invoice $invoice): string => (string) $invoice->issue_date?->toDateString(),
            __('Buyer') => fn (Invoice $invoice): string => (string) $invoice->buyer_name,
            __('Buyer VAT number') => fn (Invoice $invoice): string => (string) $invoice->buyer_vat_number,
            __('Branch') => fn (Invoice $invoice): string => (string) $invoice->branch?->name,
            __('Status') => fn (Invoice $invoice): string => $statuses[$value($invoice->status)] ?? $value($invoice->status),
            __('Currency') => fn (Invoice $invoice): string => (string) $invoice->currency,
            __('Net') => fn (Invoice $invoice): string => $money($invoice->subtotal),
            __('VAT') => fn (Invoice $invoice): string => $money($invoice->tax_amount),
            __('Total') => fn (Invoice $invoice): string => $money($invoice->total),
            __('Issued by') => fn (Invoice $invoice): string => $people[(string) $invoice->issued_by] ?? '',
        ], $list->apply($this->query($organizationId, $notes)->with('branch:id,name'))->lazy(500));
    }

    /** @return array<string, string> */
    public static function documentLabels(): array
    {
        return [
            DocumentType::Invoice->value => __('Invoice'),
            DocumentType::CreditNote->value => __('Credit note'),
            DocumentType::DebitNote->value => __('Debit note'),
            DocumentType::Prepayment->value => __('Prepayment invoice'),
            DocumentType::Proforma->value => __('Proforma invoice'),
        ];
    }

    /**
     * The states an invoice is shown in. Submitted is never written
     * (portal.partials.invoice-status), so it is not offered.
     *
     * @return array<string, string>
     */
    public static function statusLabels(): array
    {
        return [
            InvoiceStatus::Draft->value => __('Draft'),
            InvoiceStatus::Issued->value => __('Issued'),
            InvoiceStatus::Accepted->value => __('Accepted'),
            InvoiceStatus::Rejected->value => __('Rejected'),
        ];
    }

    /** @return array<string, string> */
    private static function typeLabels(): array
    {
        return [
            'standard' => __('Standard (to a business)'),
            'simplified' => __('Simplified (to a consumer)'),
        ];
    }

    /**
     * The organization's documents on the branches the list shows
     * (BranchScope): the one chosen in the switcher, or everything the
     * person may see.
     *
     * @return Builder<Invoice>
     */
    private function query(string $organizationId, bool $notes): Builder
    {
        return $this->scope->applyVisible(Invoice::query()
            ->where('org_id', $organizationId)
            ->when($notes, fn (QueryBuilder $query) => $query->whereIn('document_type', self::NOTES)));
    }

    /**
     * Each device an invoice can have been signed as: head office's, which
     * is the organization's own id, and each branch's.
     *
     * @param  list<array{id: string, name: string, till_number: int|null}>  $tills
     * @param  bool  $headOffice  whether the person sees head office's own
     * @return array<string, string>
     */
    private function devices(string $organizationId, array $tills, bool $headOffice): array
    {
        $devices = $headOffice ? [$organizationId => __('Head office')] : [];

        foreach ($tills as $till) {
            $devices[$till['id']] = $till['till_number'] === null
                ? $till['name']
                : __('Till :number · :name', ['number' => $till['till_number'], 'name' => $till['name']]);
        }

        return $devices;
    }

    /**
     * The currencies the organization has invoiced in, at most twenty.
     *
     * @return list<string>
     */
    private function currencies(string $organizationId): array
    {
        return Invoice::query()
            ->where('org_id', $organizationId)
            ->whereNotNull('currency')
            ->distinct()
            ->orderBy('currency')
            ->limit(20)
            ->pluck('currency')
            ->map(fn (mixed $currency): string => (string) $currency)
            ->all();
    }
}
