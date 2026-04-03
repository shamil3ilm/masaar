<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Fatoora;

use App\Domains\Compliance\Contracts\ComplianceEngine;
use App\Domains\Compliance\Contracts\SubmissionResult;
use App\Domains\Compliance\Contracts\ValidationResult;
use App\Domains\Compliance\Fatoora\Services\FatooraSubmissionService;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\ComplianceProfile;
use Illuminate\Support\Facades\Log;

class FatooraEngine implements ComplianceEngine
{
    public function __construct(
        private readonly FatooraSubmissionService $submissionService,
    ) {}

    public function supports(string $jurisdiction): bool
    {
        return $jurisdiction === 'SA';
    }

    public function submit(Invoice $invoice, ComplianceProfile $profile): SubmissionResult
    {
        $organization = $profile->organization;

        try {
            $response = $this->submissionService->submit($invoice, $organization);

            return new SubmissionResult(
                success: $response->success,
                submissionId: null, // Fatoora is synchronous — no async submission ID
                referenceId: $response->clearanceStatus,
                status: $response->success ? 'accepted' : 'rejected',
                rawResponse: ['raw' => $response->rawResponse, 'validation' => $response->validationResults],
                errorMessage: $response->success ? null : implode(', ', $response->errorMessages),
            );
        } catch (\Throwable $e) {
            Log::error('FatooraEngine::submit failed', ['error' => $e->getMessage()]);

            return SubmissionResult::failure($e->getMessage());
        }
    }

    public function retry(string $submissionId, ComplianceProfile $profile): SubmissionResult
    {
        // Fatoora is synchronous — retry means resubmit via submit()
        return SubmissionResult::failure('Fatoora does not support retry by submission ID. Resubmit the invoice.');
    }

    public function checkStatus(string $submissionId, ComplianceProfile $profile): SubmissionResult
    {
        // Fatoora is synchronous — status is in the submission response
        return SubmissionResult::failure('Fatoora submissions are synchronous. Check invoice.zatca_response directly.');
    }

    public function validate(Invoice $invoice, ComplianceProfile $profile): ValidationResult
    {
        $organization = $profile->organization;

        try {
            $response = $this->submissionService->validate($invoice, $organization);

            if ($response->success) {
                return ValidationResult::pass($response->warningMessages);
            }

            return ValidationResult::fail(
                errors: $response->errorMessages,
                warnings: $response->warningMessages,
            );
        } catch (\Throwable $e) {
            return ValidationResult::fail([$e->getMessage()]);
        }
    }
}
