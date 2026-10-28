<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Http\Controllers;

use App\Domains\Invoice\Http\Requests\AdjustInvoiceRequest;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Invoice\Models\InvoiceAdjustment;
use App\Domains\Invoice\Services\InvoiceAdjustments;
use App\Domains\Invoice\Services\InvoiceFinder;
use App\Domains\Organization\Services\TenantResolver;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * A return, an exchange, a replacement or a correction on an issued
 * invoice, through the API, in one call (InvoiceAdjustments): the notes it
 * calls for issued - or held for approval, with the request they wait on -
 * and any new invoice left a draft, all linked to the original.
 *
 * POST /api/invoices/{invoice}/adjustments; the partner API's
 * /v1/invoices/{invoice}/adjustments the same. Sent with an
 * Idempotency-Key (the idempotent middleware), a retry answers what the
 * first call made. preview: true answers what it would make, and makes
 * nothing.
 */
class InvoiceAdjustmentController extends Controller
{
    public function __construct(
        private readonly TenantResolver $tenant,
        private readonly InvoiceFinder $invoices,
        private readonly InvoiceAdjustments $adjustments,
    ) {}

    public function store(AdjustInvoiceRequest $request, string $invoice): JsonResponse
    {
        $organization = $this->tenant->getOrganization() ?? abort(404);
        $original = $this->invoices->findWithLines((string) $organization->id, $invoice);
        $person = $request->user();

        // A person limited to other branches does not adjust this one's.
        if ($person !== null && Gate::forUser($person)->denies('issue', $original)) {
            return ApiResponse::forbidden(__('You cannot issue invoices at this branch.'));
        }

        $input = $request->safe()->except('preview');

        try {
            if ($request->boolean('preview')) {
                return ApiResponse::success(['preview' => $this->adjustments->plan($organization, $original, $input)->summary()], 'What the adjustment would make; nothing was made');
            }

            $made = $this->adjustments->apply($organization, $original, $input, $person, integration: true);
        } catch (DomainException $e) {
            return ApiResponse::error($e->getMessage(), 422);
        }

        return ApiResponse::created(self::present($made['adjustment'], $made['documents']), 'Adjustment recorded');
    }

    /**
     * @param  list<array{role: string, invoice: Invoice, status: string, approval: array<string, mixed>|null, message: string|null}>  $documents
     * @return array<string, mixed>
     */
    public static function present(InvoiceAdjustment $adjustment, array $documents): array
    {
        return [
            'adjustment' => [
                'id' => (string) $adjustment->id,
                'invoice_id' => (string) $adjustment->invoice_id,
                'kind' => $adjustment->kind->value,
                'reason' => $adjustment->reason,
                'details' => $adjustment->details,
                'created_at' => $adjustment->created_at?->toIso8601String(),
            ],
            'documents' => array_map(fn (array $document): array => [
                'role' => $document['role'],
                'invoice_id' => (string) $document['invoice']->id,
                'document_type' => $document['invoice']->document_type?->value,
                'invoice_number' => $document['invoice']->fresh()?->invoice_number,
                'status' => $document['status'],
                'total' => (string) $document['invoice']->total,
                'approval' => $document['approval'],
                'message' => $document['message'],
            ], $documents),
        ];
    }
}
