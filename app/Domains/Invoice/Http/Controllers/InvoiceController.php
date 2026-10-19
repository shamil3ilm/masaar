<?php

namespace App\Domains\Invoice\Http\Controllers;

use App\Domains\Invoice\Enums\PaperSize;
use App\Domains\Invoice\Exceptions\InvoiceNotEditableException;
use App\Domains\Invoice\Http\Requests\CreateInvoiceRequest;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Invoice\Services\IdempotentDraft;
use App\Domains\Invoice\Services\InvoiceDrafter;
use App\Domains\Invoice\Services\InvoiceFinder;
use App\Domains\Invoice\Services\InvoicePdf;
use App\Domains\Invoice\Services\MissingText;
use App\Domains\Organization\Services\BranchAccess;
use App\Domains\Organization\Services\PrintPreferences;
use App\Domains\Organization\Services\TenantResolver;
use App\Domains\Organization\Services\Tills;
use App\Http\Controllers\Controller;
use App\Http\Pagination\PageSize;
use App\Http\Responses\ApiResponse;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * Invoice API controller.
 */
class InvoiceController extends Controller
{
    /**
     * Header fields a draft may change through the API.
     */
    private const REVISABLE = [
        'invoice_number',
        'issue_date',
        'supply_date',
        'buyer_name',
        'buyer_vat_number',
        'buyer_address',
        'notes',
    ];

    public function __construct(
        private readonly TenantResolver $tenant,
        private readonly InvoiceDrafter $drafter,
        private readonly InvoiceFinder $invoices,
        private readonly InvoicePdf $pdf,
        private readonly IdempotentDraft $once,
        private readonly MissingText $missing,
        private readonly BranchAccess $branches,
        private readonly Tills $tills,
    ) {}

    /**
     * List invoices for current organization.
     *
     * GET /api/invoices
     */
    public function index(Request $request): JsonResponse
    {
        $invoices = $this->invoices->paginate(
            $this->tenant->getOrganizationId(),
            $this->statusFilter($request),
            PageSize::fromRequest($request, 15)
        );

        return ApiResponse::paginated($invoices);
    }

    /**
     * Create a new invoice.
     *
     * POST /api/invoices
     *
     * An Idempotency-Key header makes a retry answer with the invoice the
     * first request created - 200 and Idempotent-Replayed: true, where the
     * first was 201 - instead of drafting the sale again. The key means what
     * it means on the pipeline (IdempotentDraft). branch_id names the branch
     * the invoice comes from, which decides the certificate that signs it.
     */
    public function store(CreateInvoiceRequest $request): JsonResponse
    {
        $organizationId = $this->tenant->getOrganizationId();
        $key = $request->header('Idempotency-Key') ?: null;

        // As the organization's country's rules map the request.
        $draft = $request->draft();

        // The till that made the sale, when the request names one; its
        // branch is the invoice's when the request names none.
        try {
            ['branch' => $branchId, 'till' => $tillId] = $this->tills->resolve(
                (string) $organizationId,
                $request->validated('branch_id'),
                $request->validated('till_id'),
                $request->validated('till_number') === null ? null : (int) $request->validated('till_number'),
            );
        } catch (DomainException $e) {
            return ApiResponse::validationError(['till_id' => [$e->getMessage()]]);
        }

        // A person signed in through a token issues only at a branch they
        // work at, and a cashier's invoice names their branch when it names
        // none; an integration's key is not a person, and names what it likes.
        $userId = $request->user()?->getAuthIdentifier();

        if ($userId !== null && $this->tenant->getRole() !== 'service') {
            try {
                $branchId = $this->branches->draftBranch((string) $userId, (string) $organizationId, $branchId);
            } catch (DomainException $e) {
                return ApiResponse::forbidden($e->getMessage());
            }
        }

        $invoice = $this->once->earlier($organizationId, $key, $draft)
            ?? $this->once->draft($draft, $organizationId, $branchId, $request->countryFields(), $key, $tillId);

        if (! $invoice->wasRecentlyCreated) {
            return ApiResponse::success([
                'invoice' => $invoice->load('lines'),
            ], 'Invoice already created under this Idempotency-Key')->header('Idempotent-Replayed', 'true');
        }

        return ApiResponse::created([
            'invoice' => $invoice->load('lines'),
            ...$this->warnings($invoice),
        ], 'Invoice created');
    }

    /**
     * Get single invoice.
     *
     * GET /api/invoices/{id}
     */
    public function show(string $id): JsonResponse
    {
        $invoice = $this->invoices->findWithLines($this->tenant->getOrganizationId(), $id);

        return ApiResponse::success(['invoice' => $invoice]);
    }

    /**
     * The issued invoice as a PDF/A-3 with its XML inside.
     *
     * GET /api/invoices/{id}/pdf?paper=a4|letter|a5|80mm|58mm
     *
     * On the paper asked for, else the caller's, the invoice's branch's or
     * the organization's (PrintPreferences).
     *
     * 409 for a draft, or a standard invoice ZATCA has not cleared yet: it
     * may not be given to the buyer before then.
     */
    public function pdf(Request $request, string $id, PrintPreferences $preferences): Response|JsonResponse
    {
        $paper = $request->validate(['paper' => ['nullable', Rule::enum(PaperSize::class)]])['paper'] ?? null;
        $invoice = $this->invoices->findWithLines($this->tenant->getOrganizationId(), $id);
        $organization = $this->tenant->getOrganization() ?? abort(404);

        try {
            $pdf = $this->pdf->render($invoice, $organization, $preferences->paper($organization, $invoice->branch_id, $request->user()?->id, $paper));
        } catch (DomainException $e) {
            return ApiResponse::error($e->getMessage(), 409);
        }

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$this->pdf->fileName($invoice).'"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Update draft invoice.
     *
     * PUT /api/invoices/{id}
     */
    public function update(CreateInvoiceRequest $request, string $id): JsonResponse
    {
        $invoice = $this->invoices->find($this->tenant->getOrganizationId(), $id);

        try {
            $invoice = $this->drafter->revise($invoice, $request->only(self::REVISABLE));
        } catch (InvoiceNotEditableException) {
            return ApiResponse::error('Invoice cannot be edited after issuance', 422);
        }

        $invoice = $invoice->fresh('lines');

        return ApiResponse::success([
            'invoice' => $invoice,
            ...$this->warnings($invoice),
        ], 'Invoice updated');
    }

    /**
     * The text the invoice still lacks, which only a grace period lets
     * through (MissingText): `warnings` beside the invoice, and nothing
     * when there is none.
     *
     * @return array{warnings?: list<string>}
     */
    private function warnings(Invoice $invoice): array
    {
        $warnings = $this->missing->warnings($invoice, $this->tenant->getOrganization());

        return $warnings === [] ? [] : ['warnings' => $warnings];
    }

    /**
     * Delete draft invoice.
     *
     * DELETE /api/invoices/{id}
     */
    public function destroy(string $id): JsonResponse
    {
        $invoice = $this->invoices->find($this->tenant->getOrganizationId(), $id);

        try {
            $this->drafter->discard($invoice);
        } catch (InvoiceNotEditableException) {
            return ApiResponse::error('Cannot delete issued invoice', 422);
        }

        return ApiResponse::success(null, 'Invoice deleted');
    }

    /**
     * The status to list, or null for every status.
     *
     * A status parameter that is present filters even when blank, which then
     * matches nothing; one that is not a string is treated as blank.
     */
    private function statusFilter(Request $request): ?string
    {
        if (! $request->has('status')) {
            return null;
        }

        $status = $request->input('status');

        return is_string($status) ? $status : '';
    }
}
