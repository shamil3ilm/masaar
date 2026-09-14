<?php

declare(strict_types=1);

namespace App\Domains\Compliance\FTA\Http\Controllers;

use App\Domains\Compliance\FTA\Services\FtaService;
use App\Domains\Compliance\FTA\Services\SubmissionFinder;
use App\Domains\Invoice\Services\InvoiceFinder;
use App\Domains\Organization\Services\TenantResolver;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * UAE FTA e-Invoicing Controller.
 *
 * POST /api/compliance/ae/submit/{invoiceId}      — generate + submit
 * GET  /api/compliance/ae/status/{submissionId}   — check status
 * POST /api/compliance/ae/retry/{submissionId}    — retry failed submission
 * GET  /api/compliance/ae/submissions             — list submissions
 */
class FtaController extends Controller
{
    public function __construct(
        private readonly TenantResolver $tenant,
        private readonly FtaService $ftaService,
        private readonly SubmissionFinder $submissions,
        private readonly InvoiceFinder $invoices,
    ) {}

    public function submit(string $invoiceId): JsonResponse
    {
        $invoice = $this->invoices->find($this->tenant->getOrganizationId(), $invoiceId);
        $organization = $this->tenant->getOrganization();

        $submission = $this->ftaService->submit($invoice, $organization);

        return ApiResponse::success([
            'submission_id' => $submission->id,
            'status' => $submission->status->value,
            'fta_ref' => $submission->reference,
        ], 'UAE FTA invoice submitted', 201);
    }

    public function status(string $submissionId): JsonResponse
    {
        $submission = $this->submissions->find($submissionId);
        $updated = $this->ftaService->checkStatus($submission);

        return ApiResponse::success([
            'submission_id' => $updated->id,
            'status' => $updated->status->value,
            'fta_ref' => $updated->reference,
            'validation_status' => $updated->validation_status,
            'warnings' => $updated->warnings,
            'errors' => $updated->errors,
            'submitted_at' => $updated->submitted_at?->toISOString(),
            'accepted_at' => $updated->accepted_at?->toISOString(),
        ]);
    }

    public function retry(string $submissionId): JsonResponse
    {
        $submission = $this->submissions->find($submissionId);
        $updated = $this->ftaService->retry($submission);

        return ApiResponse::success([
            'submission_id' => $updated->id,
            'status' => $updated->status->value,
            'retry_count' => $updated->retry_count,
        ], 'UAE FTA submission retried');
    }

    public function index(): JsonResponse
    {
        $organization = $this->tenant->getOrganization();

        return ApiResponse::success($this->submissions->paginate($organization->id, 25));
    }
}
