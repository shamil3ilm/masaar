<?php

declare(strict_types=1);

namespace App\Domains\Compliance\FTA\Http\Controllers;

use App\Domains\Compliance\FTA\Models\FtaSubmission;
use App\Domains\Compliance\FTA\Services\FtaService;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Services\TenantResolver;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * UAE FTA e-Invoicing Controller.
 *
 * POST /api/compliance/uae-fta/submit/{invoiceId}      — generate + submit
 * GET  /api/compliance/uae-fta/status/{submissionId}   — check status
 * POST /api/compliance/uae-fta/retry/{submissionId}    — retry failed submission
 * GET  /api/compliance/uae-fta/submissions             — list submissions
 */
class FtaController extends Controller
{
    public function __construct(
        private readonly TenantResolver $tenant,
        private readonly FtaService $ftaService,
    ) {}

    public function submit(string $invoiceId): JsonResponse
    {
        $invoice = $this->getInvoice($invoiceId);
        $organization = $this->tenant->getOrganization();

        $submission = $this->ftaService->submit($invoice, $organization);

        return ApiResponse::success([
            'submission_id' => $submission->id,
            'status' => $submission->status->value,
            'fta_ref' => $submission->fta_submission_id,
        ], 'UAE FTA invoice submitted', 201);
    }

    public function status(string $submissionId): JsonResponse
    {
        $submission = FtaSubmission::findOrFail($submissionId);
        $updated = $this->ftaService->checkStatus($submission);

        return ApiResponse::success([
            'submission_id' => $updated->id,
            'status' => $updated->status->value,
            'fta_ref' => $updated->fta_submission_id,
            'fta_validation_status' => $updated->fta_validation_status,
            'warnings' => $updated->fta_warnings,
            'errors' => $updated->fta_errors,
            'submitted_at' => $updated->submitted_at?->toISOString(),
            'accepted_at' => $updated->accepted_at?->toISOString(),
        ]);
    }

    public function retry(string $submissionId): JsonResponse
    {
        $submission = FtaSubmission::findOrFail($submissionId);
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

        $submissions = FtaSubmission::where('organization_id', $organization->id)
            ->with('invoice:id,invoice_number')
            ->latest()
            ->paginate(25);

        return ApiResponse::success($submissions);
    }

    // ----------------------------------------------------------------

    private function getInvoice(string $id): Invoice
    {
        return Invoice::findOrFail($id);
    }
}
