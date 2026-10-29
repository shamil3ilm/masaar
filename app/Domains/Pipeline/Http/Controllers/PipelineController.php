<?php

declare(strict_types=1);

namespace App\Domains\Pipeline\Http\Controllers;

use App\Domains\Compliance\Engine\Services\JurisdictionResolver;
use App\Domains\Invoice\Services\IdempotentDraft;
use App\Domains\Invoice\Services\InvoiceFinder;
use App\Domains\Licensing\Http\Middleware\RequireLicenseFeature;
use App\Domains\Organization\Services\TenantResolver;
use App\Domains\Pipeline\Http\Requests\PipelineBatchRequest;
use App\Domains\Pipeline\Http\Requests\PipelineSplitRequest;
use App\Domains\Pipeline\Http\Requests\PipelineSubmitRequest;
use App\Domains\Pipeline\Services\PipelineService;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Http\Responses\ResponseData;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Redirector;
use Symfony\Component\HttpFoundation\InputBag;
use Throwable;

/**
 * Pipeline API controller for atomic ERP-ZATCA integration.
 *
 * Provides a single endpoint that combines invoice creation,
 * compliance data generation, and ZATCA submission into one
 * atomic HTTP request.
 */
class PipelineController extends Controller
{
    public function __construct(
        private readonly PipelineService $pipelineService,
        private readonly TenantResolver $tenant,
        private readonly InvoiceFinder $invoices,
        private readonly JurisdictionResolver $jurisdictions,
    ) {}

    /**
     * Submit an invoice through the full pipeline.
     *
     * POST /api/v1/pipeline/submit
     *
     * Creates the invoice, generates compliance data (hash, QR, signed XML),
     * and optionally submits to ZATCA government API in a single request.
     */
    #[ResponseData('PipelineResult')]
    public function submit(PipelineSubmitRequest $request): JsonResponse
    {
        $data = $request->validated();

        $organizationId = $data['org_id'];

        if ($refusal = $this->refuseOrganization($organizationId)) {
            return $refusal;
        }

        $result = $this->pipelineService->submitInvoice(
            data: $data,
            organizationId: $organizationId,
            branchId: $data['branch_id'] ?? null,
            // An ERP retrying a timed-out call sends the same key and gets the
            // original outcome rather than filing the invoice a second time.
            // A blank header is none: stored, it would make every other
            // blank-keyed request a retry of the first.
            idempotencyKey: $request->header('Idempotency-Key') ?: null,
            sent: IdempotentDraft::sent($request),
        );

        [$status, $message] = $this->outcome($result);

        return $this->replayed($status === 201
            ? ApiResponse::created(data: $result, message: $message)
            : ApiResponse::success(data: $result, message: $message, status: $status));
    }

    /**
     * A sale an insurer pays for, wholly or in part, invoiced to its
     * recipient - the patient, with the insurer's share for its account, or
     * the insurer that contracted for the care (InsuranceSplits) - drafted,
     * issued and filed in one call.
     *
     * POST /api/v1/pipeline/split
     *
     * The invoice's result is as /pipeline/submit's would be, and so is the
     * status. An Idempotency-Key makes a retry carry on from the first call.
     */
    public function split(PipelineSplitRequest $request): JsonResponse
    {
        $data = $request->validated();

        if ($refusal = $this->refuseOrganization($data['org_id'])) {
            return $refusal;
        }

        $result = $this->pipelineService->submitSplit(
            data: $data,
            organizationId: $data['org_id'],
            branchId: $data['branch_id'] ?? null,
            idempotencyKey: $request->header('Idempotency-Key') ?: null,
            sent: IdempotentDraft::sent($request),
        );

        [$status, $message] = $result['invoice'] === null ? [207, 'The invoice was not drafted.'] : $this->outcome($result['invoice']);

        return $this->replayed($status === 201
            ? ApiResponse::created(data: $result, message: $message)
            : ApiResponse::success(data: $result, message: $message, status: $status));
    }

    /**
     * A retry answered from what the first call made says so, with the
     * status the invoice's state earns now (201, 202 or 207).
     */
    private function replayed(JsonResponse $response): JsonResponse
    {
        return $this->pipelineService->replayed() ? $response->header('Idempotent-Replayed', 'true') : $response;
    }

    /**
     * Submit many invoices, each as its own pipeline request.
     *
     * POST /api/v1/pipeline/batch
     *
     * Each invoice is licensed, validated, drafted, issued and filed on its
     * own, under its own idempotency_key, exactly as /pipeline/submit would
     * take it - and answered on its own line with the status that endpoint
     * would have given it, and its result or its error. One that fails does
     * not stop the rest. 201 when every invoice was submitted cleanly, 207
     * when any was not.
     */
    public function batch(PipelineBatchRequest $request): JsonResponse
    {
        $organizationId = (string) $request->validated('org_id');

        if ($refusal = $this->refuseOrganization($organizationId)) {
            return $refusal;
        }

        // Its documents' kinds: a note correcting an issued invoice goes on
        // through an overdue-payment freeze, which each invoice meets alone.
        $this->pipelineService->assertMayBatch($organizationId, array_column(array_filter((array) $request->input('invoices'), 'is_array'), 'document_type'));

        $results = [];
        $sent = IdempotentDraft::sent($request);

        // The bodies as sent: validated() holds only the fields the envelope
        // checks, and each invoice is checked whole below.
        foreach ((array) $request->input('invoices') as $index => $item) {
            $results[] = $this->batchItem($request, $index, [...$item, 'org_id' => $organizationId], (array) ($sent['invoices'][$index] ?? $item));
        }

        $failed = count(array_filter($results, fn (array $result): bool => $result['http_status'] !== 201));
        $data = [
            'results' => $results,
            'summary' => ['total' => count($results), 'succeeded' => count($results) - $failed, 'failed' => $failed],
        ];

        return $failed === 0
            ? ApiResponse::created(data: $data, message: 'Every invoice was submitted through the pipeline')
            : ApiResponse::success(data: $data, message: 'Some invoices were not submitted cleanly. See each result.', status: 207);
    }

    /**
     * One invoice of a batch, as /pipeline/submit takes it.
     *
     * Its body becomes a PipelineSubmitRequest of its own, so it meets the
     * same rules, and the licence is asked about its document as
     * license.feature:documents asks on the single endpoint. A refusal is
     * rendered by the application's exception handler, as the single
     * endpoint's would be, and becomes this item's status and error.
     *
     * @param  array<string, mixed>  $item
     * @param  array<string, mixed>  $sent  The invoice as the client sent it, for its key's fingerprint
     * @return array{index: int, idempotency_key: ?string, http_status: int, message: string, data: ?array, error: ?array}
     */
    private function batchItem(Request $batch, int $index, array $item, array $sent): array
    {
        $key = filled($item['idempotency_key'] ?? null) ? (string) $item['idempotency_key'] : null;

        try {
            $request = $this->itemRequest($batch, $item);

            app(RequireLicenseFeature::class)->handle($request, fn () => null, RequireLicenseFeature::DOCUMENTS);
            $request->validateResolved();
            $data = $request->validated();

            $result = $this->pipelineService->submitInvoice($data, (string) $data['org_id'], $data['branch_id'] ?? null, $key, $sent);
            [$status, $message] = $this->outcome($result);

            return ['index' => $index, 'idempotency_key' => $key, 'http_status' => $status, 'message' => $message, 'data' => $result, 'error' => null];
        } catch (Throwable $e) {
            $response = app(ExceptionHandler::class)->render($batch, $e);
            $body = json_decode((string) $response->getContent(), true);

            return [
                'index' => $index,
                'idempotency_key' => $key,
                'http_status' => $response->getStatusCode(),
                'message' => (string) ($body['error']['message'] ?? $body['message'] ?? ''),
                'data' => null,
                'error' => is_array($body['error'] ?? null) ? $body['error'] : null,
            ];
        }
    }

    /**
     * The batch's request, carrying one invoice's body: its credential,
     * licence and tenant, and nothing of the other invoices.
     *
     * @param  array<string, mixed>  $item
     */
    private function itemRequest(Request $batch, array $item): PipelineSubmitRequest
    {
        $request = PipelineSubmitRequest::createFrom($batch);
        $request->setJson(new InputBag($item));
        $request->request = new InputBag($item);
        $request->setContainer(app())->setRedirector(app(Redirector::class));

        return $request;
    }

    /**
     * The body names an organization; the credential decides which one is
     * allowed. Missing context is refused rather than waved through — the
     * previous guard skipped itself when the tenant was null, so the one
     * case where it could not tell was the one it let past.
     */
    private function refuseOrganization(string $organizationId): ?JsonResponse
    {
        $authenticatedOrgId = $this->tenant->getOrganizationId();

        if ($authenticatedOrgId === null) {
            return ApiResponse::error('Organization context is required.', 401);
        }

        if ($authenticatedOrgId !== $organizationId) {
            return ApiResponse::forbidden(
                'org_id does not match the authenticated API key\'s organization.'
            );
        }

        return null;
    }

    /**
     * The status and message a pipeline result answers with.
     *
     * @param  array<string, mixed>  $result
     * @return array{0: int, 1: string}
     */
    private function outcome(array $result): array
    {
        $hasErrors = ! empty($result['errors']);
        $status = $result['status'];

        // A note held for the organization's approval: accepted, not issued.
        // Once approved it is issued and filed; /pipeline/status says so.
        if (isset($result['approval'])) {
            return [202, 'The note waits for approval in the organization. It is issued once approved.'];
        }

        // Invoice created but compliance generation failed
        if ($hasErrors && $status === 'draft') {
            return [207, 'Invoice created but compliance generation failed. See errors for details.'];
        }

        // Invoice issued but ZATCA submission had issues
        if ($hasErrors && in_array($status, ['issued', 'rejected'], true)) {
            return [207, 'Invoice processed with warnings or errors. See errors for details.'];
        }

        return [201, 'Invoice submitted through the pipeline'];
    }

    /**
     * Get pipeline status for an invoice.
     *
     * GET /api/v1/pipeline/status/{invoiceId}
     */
    #[ResponseData('PipelineStatus')]
    public function status(Request $request, string $invoiceId): JsonResponse
    {
        $authenticatedOrgId = $this->tenant->getOrganizationId();

        if ($authenticatedOrgId === null) {
            return ApiResponse::error('Organization context is required.', 401);
        }

        $invoice = $this->invoices->findWithLines($authenticatedOrgId, $invoiceId);

        // Not before the buyer may have it (PipelineResult says why).
        $withheld = $this->jurisdictions->forRules($this->tenant->getOrganization())->presentation()->withheld($invoice);

        return ApiResponse::success([
            'invoice_id' => $invoice->id,
            'uuid' => $invoice->id,
            'invoice_number' => $invoice->invoice_number,
            'status' => $invoice->status->value,
            'type' => $invoice->type->value,
            'hash' => $invoice->hash,
            'qr_code' => $withheld ? null : $invoice->qr_code,
            // ZATCA's cleared copy when there is one; see PipelineResult.
            'signed_xml' => $withheld ? null : $invoice->legal_xml,
            'cleared_xml' => $invoice->cleared_xml,
            'zatca_response' => $invoice->zatca_response,
            'totals' => [
                'subtotal' => $invoice->subtotal,
                'discount_amount' => $invoice->discount_amount,
                'tax_amount' => $invoice->tax_amount,
                'total' => $invoice->total,
            ],
            'issue_date' => $invoice->issue_date?->toDateString(),
            'created_at' => $invoice->created_at?->toIso8601String(),
            'updated_at' => $invoice->updated_at?->toIso8601String(),
        ], 'Invoice status retrieved');
    }
}
