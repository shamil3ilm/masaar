<?php

namespace App\Domains\Compliance\Fatoora\Http\Controllers;

use App\Domains\Compliance\Fatoora\Enums\ErrorCode;
use App\Domains\Compliance\Fatoora\Exceptions\FatooraException;
use App\Domains\Compliance\Fatoora\Services\Submitter;
use App\Domains\Invoice\Enums\InvoiceStatus;
use App\Domains\Invoice\Services\InvoiceFinder;
use App\Domains\Organization\Services\TenantResolver;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Fatoora (KSA) Compliance API controller.
 *
 * Thin controller - delegates to Submitter.
 */
class ComplianceController extends Controller
{
    public function __construct(
        private readonly TenantResolver $tenant,
        private readonly Submitter $submission,
        private readonly InvoiceFinder $invoices,
    ) {}

    /**
     * Generate compliance data for invoice (hash, QR).
     *
     * POST /api/compliance/sa/generate/{invoiceId}
     */
    public function generate(string $invoiceId): JsonResponse
    {
        $invoice = $this->invoices->findWithLines($this->tenant->getOrganizationId(), $invoiceId);
        $organization = $this->tenant->getOrganization();

        // No reissue opt-in here: this route is the vector. A client retrying
        // after a failed submit() calls this on an already-issued invoice, and
        // re-signing would fork the chain. The refusal is a client error about
        // the invoice's state, so it reads like submit()'s below rather than
        // like a crash.
        try {
            $result = $this->submission->generate($invoice, $organization);
        } catch (FatooraException $e) {
            // Only this refusal is answered here. Everything else generate()
            // throws goes to the bootstrap handler, which carries the error
            // code, category and retry guidance - a kill switch is a
            // retryable 503, and flattening it to 422 would tell a client to
            // stop retrying something that will succeed in a minute.
            if ($e->getErrorCode() !== ErrorCode::VAL_INVOICE_ALREADY_ISSUED) {
                throw $e;
            }

            // Through fromFatoora so this refusal carries the same code,
            // category and retry guidance the bootstrap handler gives the
            // rethrown ones. It used to pass getMessage() alone, which read
            // the ErrorCode on the line above and then discarded it, so one
            // ZATCA condition answered in two shapes depending on which
            // branch fired.
            return ApiResponse::fromFatoora($e);
        }

        return ApiResponse::success($result, 'Compliance data generated');
    }

    /**
     * Validate invoice with Fatoora (without submission).
     *
     * POST /api/compliance/sa/validate/{invoiceId}
     */
    public function validate(string $invoiceId): JsonResponse
    {
        $invoice = $this->invoices->findWithLines($this->tenant->getOrganizationId(), $invoiceId);
        $organization = $this->tenant->getOrganization();

        $response = $this->submission->validate($invoice, $organization);

        return ApiResponse::success([
            'valid' => $response->success,
            'status' => $response->validationStatus,
            'warnings' => $response->warningMessages,
            'errors' => $response->errorMessages,
        ]);
    }

    /**
     * Submit invoice to Fatoora.
     *
     * POST /api/compliance/sa/submit/{invoiceId}
     */
    public function submit(string $invoiceId): JsonResponse
    {
        $invoice = $this->invoices->findWithLines($this->tenant->getOrganizationId(), $invoiceId);

        if ($invoice->status !== InvoiceStatus::Issued) {
            return ApiResponse::error('Invoice must be issued before submission', 422);
        }

        $organization = $this->tenant->getOrganization();

        $response = $this->submission->submit($invoice, $organization);

        if (! $response->success) {
            // The status comes off the code the response carries rather
            // than being hardcoded. A flat 422 flattened every submission
            // failure into a non-retryable client error - a kill switch, an
            // unreachable authority and a timeout all told the caller to stop
            // trying and go fix its invoice.
            return ApiResponse::error(
                'Fatoora submission failed',
                $response->errorCode?->getHttpStatus() ?? 422,
                $response->errorMessages,
                $response->errorCode?->value,
                $response->errorCode?->getCategory(),
                $response->isRetryable() ? $response->errorCode?->getRetryDelay() : null,
            );
        }

        return ApiResponse::success([
            'status' => $response->clearanceStatus ?? $response->reportingStatus,
            'warnings' => $response->warningMessages,
        ], 'Invoice submitted');
    }

    /**
     * Get invoice status.
     *
     * GET /api/compliance/sa/status/{invoiceId}
     */
    public function status(string $invoiceId): JsonResponse
    {
        $invoice = $this->invoices->findWithLines($this->tenant->getOrganizationId(), $invoiceId);

        return ApiResponse::success([
            'invoice_id' => $invoice->id,
            'status' => $invoice->status->value,
            'hash' => $invoice->hash,
            'qr_code' => $invoice->qr_code,
            'zatca_response' => $invoice->zatca_response,
        ]);
    }
}
