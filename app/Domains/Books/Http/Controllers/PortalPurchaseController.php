<?php

declare(strict_types=1);

namespace App\Domains\Books\Http\Controllers;

use App\Domains\Billing\Services\ExpenseReceipts;
use App\Domains\Books\DTOs\PurchaseEntry;
use App\Domains\Books\DTOs\PurchaseLineEntry;
use App\Domains\Books\Enums\PurchaseTreatment;
use App\Domains\Books\Services\BooksCountry;
use App\Domains\Books\Services\PurchaseHistory;
use App\Domains\Books\Services\PurchaseImport;
use App\Domains\Books\Services\Purchases;
use App\Domains\Books\Services\Suppliers;
use App\Domains\Organization\Enums\MemberRole;
use App\Domains\Organization\Http\Concerns\ResolvesPortalOrganization;
use App\Domains\Organization\Services\TenantResolver;
use App\Http\Controllers\Controller;
use App\Http\Listing\Lists;
use App\Http\Listing\ListState;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Purchase invoices and supplier credit notes: the list, recording one with
 * the supplier's file, importing a spreadsheet of them, and voiding one
 * entered by mistake. BooksAccess decides who may record.
 */
class PortalPurchaseController extends Controller
{
    use ResolvesPortalOrganization;

    /** Lines the form offers; a line left blank is not one. */
    public const FORM_LINES = 5;

    public function __construct(
        private readonly TenantResolver $tenant,
        private readonly Purchases $purchases,
        private readonly PurchaseHistory $history,
        private readonly PurchaseImport $import,
        private readonly Suppliers $suppliers,
        private readonly BooksCountry $country,
        private readonly Lists $lists,
    ) {}

    public function index(Request $request): View
    {
        $organizationId = $this->portalOrganizationId($request);
        $list = $this->list($request, 'portal.books.purchases.export');

        return view('portal.books.purchases', [
            'purchases' => $this->history->page($organizationId, $list),
            'list' => $list,
            'records' => MemberRole::keepsBooks($request->user()?->roleIn($organizationId)),
            'accept' => ExpenseReceipts::ACCEPT,
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        return $this->history->csv($this->portalOrganizationId($request), $this->list($request), 'purchases-'.now()->toDateString().'.csv');
    }

    public function create(Request $request): View
    {
        $organization = $this->portalOrganization($this->tenant, $request);

        return view('portal.books.purchase-form', [
            'suppliers' => $this->suppliers->all((string) $organization->id),
            'treatments' => PurchaseTreatment::options(),
            'currency' => $this->country->currency($organization),
            'standardRate' => $this->country->standardRate($organization),
            'lines' => self::FORM_LINES,
            'accept' => ExpenseReceipts::ACCEPT,
            'maxKb' => ExpenseReceipts::MAX_KILOBYTES,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $organization = $this->portalOrganization($this->tenant, $request);
        $data = $request->validate([
            'supplier_id' => ['required', 'string'],
            'supplier_invoice_number' => ['required', 'string', 'max:100'],
            'invoice_date' => ['required', 'date'],
            'received_on' => ['nullable', 'date'],
            'document_type' => ['required', Rule::in(['invoice', 'credit_note'])],
            'original_number' => ['nullable', 'string', 'max:100'],
            'currency' => ['required', 'string', 'size:3'],
            'exchange_rate' => ['nullable', 'numeric', 'gt:0'],
            'deductible_share' => ['required', 'numeric', 'between:0,100'],
            'description' => ['nullable', 'string', 'max:500'],
            'lines' => ['required', 'array', 'max:'.self::FORM_LINES],
            'lines.*.description' => ['nullable', 'string', 'max:500'],
            'lines.*.treatment' => ['nullable', Rule::enum(PurchaseTreatment::class)],
            'lines.*.net' => ['nullable', 'numeric'],
            'lines.*.vat' => ['nullable', 'numeric'],
            'lines.*.vat_rate' => ['nullable', 'numeric', 'between:0,100'],
            'lines.*.capital_asset' => ['nullable', 'boolean'],
            'file' => ['nullable', 'file', 'max:'.ExpenseReceipts::MAX_KILOBYTES],
        ]);

        $lines = [];

        foreach ($data['lines'] as $line) {
            if (blank($line['description'] ?? null) && blank($line['net'] ?? null)) {
                continue;
            }

            $lines[] = new PurchaseLineEntry(
                description: (string) ($line['description'] ?? ''),
                treatment: PurchaseTreatment::tryFrom((string) ($line['treatment'] ?? '')) ?? PurchaseTreatment::Standard,
                net: (string) ($line['net'] ?? ''),
                vat: isset($line['vat']) ? (string) $line['vat'] : null,
                vatRate: isset($line['vat_rate']) ? (string) $line['vat_rate'] : null,
                capitalAsset: (bool) ($line['capital_asset'] ?? false),
            );
        }

        $original = null;

        if ($data['document_type'] === 'credit_note' && filled($data['original_number'] ?? null)) {
            $original = $this->purchases->invoiceNumbered((string) $organization->id, $data['supplier_id'], (string) $data['original_number'])
                ?? throw ValidationException::withMessages(['original_number' => __('No recorded invoice of this supplier has that number.')]);
        }

        try {
            $purchase = $this->purchases->record($organization, new PurchaseEntry(
                supplierId: $data['supplier_id'],
                supplierInvoiceNumber: $data['supplier_invoice_number'],
                invoiceDate: CarbonImmutable::parse($data['invoice_date']),
                currency: $data['currency'],
                lines: $lines,
                exchangeRate: isset($data['exchange_rate']) ? (string) $data['exchange_rate'] : null,
                deductibleShare: (string) $data['deductible_share'],
                documentType: $data['document_type'],
                originalId: $original?->id,
                description: $data['description'] ?? null,
                receivedOn: isset($data['received_on']) ? CarbonImmutable::parse($data['received_on']) : null,
            ), $request->file('file'));
        } catch (DomainException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('portal.books.purchases.show', $purchase->id)->with('success', __('Purchase recorded.'));
    }

    public function show(Request $request, string $purchaseId): View
    {
        $organizationId = $this->portalOrganizationId($request);

        return view('portal.books.purchase', [
            'purchase' => $this->purchases->find($organizationId, $purchaseId) ?? abort(404),
            'records' => MemberRole::keepsBooks($request->user()?->roleIn($organizationId)),
        ]);
    }

    public function void(Request $request, string $purchaseId): RedirectResponse
    {
        $purchase = $this->purchases->find($this->portalOrganizationId($request), $purchaseId) ?? abort(404);
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        try {
            $this->purchases->void($purchase, $data['reason'], (string) $request->user()?->id);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('portal.books.purchases.show', $purchase->id)->with('success', __('Purchase voided.'));
    }

    /** The supplier's file, as it was uploaded. */
    public function file(Request $request, string $purchaseId): BinaryFileResponse
    {
        $purchase = $this->purchases->find($this->portalOrganizationId($request), $purchaseId) ?? abort(404);
        $path = $this->purchases->filePath($purchase) ?? abort(404);

        return response()->download($path, (string) $purchase->file_name, ['Content-Type' => (string) $purchase->file_mime]);
    }

    public function import(Request $request): RedirectResponse
    {
        $organization = $this->portalOrganization($this->tenant, $request);
        $request->validate(['file' => ['required', 'file', 'max:5120']]);

        try {
            $result = $this->import->import($organization, (string) $request->file('file')->getRealPath());
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        if ($result['problems'] !== []) {
            return back()->with('error', __('Nothing was imported. Fix these rows and upload the file again: :problems', ['problems' => implode(' ', array_slice($result['problems'], 0, 20))]));
        }

        return redirect()->route('portal.books.purchases.index')->with('success', __('Purchases imported: :count.', ['count' => $result['recorded']]));
    }

    public function template(): Response
    {
        return response($this->import->template(), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="purchases-template.csv"',
        ]);
    }

    private function list(Request $request, ?string $export = null): ListState
    {
        $organizationId = $this->portalOrganizationId($request);

        return $this->lists->portal($this->history->spec($organizationId, 'portal.books.purchases.index'), $request, $organizationId, $export);
    }
}
