<?php

declare(strict_types=1);

namespace App\Domains\Compliance\FTA;

use App\Domains\Compliance\Contracts\ComplianceEngine;
use App\Domains\Compliance\Contracts\SubmissionResult;
use App\Domains\Compliance\Contracts\ValidationResult;
use App\Domains\Compliance\FTA\Exceptions\FtaException;
use App\Domains\Compliance\FTA\Models\FtaSubmission;
use App\Domains\Compliance\FTA\Services\FtaService;
use App\Domains\Compliance\FTA\Services\FtaValidator;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\ComplianceProfile;
use Illuminate\Support\Facades\Log;

class FtaEngine implements ComplianceEngine
{
    public function __construct(
        private readonly FtaService $ftaService,
        private readonly FtaValidator $validator,
    ) {}

    public function supports(string $jurisdiction): bool
    {
        return $jurisdiction === 'AE';
    }

    public function submit(Invoice $invoice, ComplianceProfile $profile): SubmissionResult
    {
        $organization = $profile->org;

        try {
            $submission = $this->ftaService->submit($invoice, $organization);

            return new SubmissionResult(
                success: true,
                submissionId: $submission->id,
                referenceId: $submission->reference,
                status: $submission->status->value,
                rawResponse: $submission->toArray(),
                errorMessage: null,
            );
        } catch (\Throwable $e) {
            Log::error('FtaEngine::submit failed', ['error' => $e->getMessage()]);

            return SubmissionResult::failure($e->getMessage());
        }
    }

    public function retry(string $submissionId, ComplianceProfile $profile): SubmissionResult
    {
        try {
            $submission = FtaSubmission::findOrFail($submissionId);
            $updated = $this->ftaService->retry($submission);

            return new SubmissionResult(
                success: true,
                submissionId: $updated->id,
                referenceId: $updated->reference,
                status: $updated->status->value,
                rawResponse: $updated->toArray(),
                errorMessage: null,
            );
        } catch (\Throwable $e) {
            return SubmissionResult::failure($e->getMessage());
        }
    }

    public function checkStatus(string $submissionId, ComplianceProfile $profile): SubmissionResult
    {
        try {
            $submission = FtaSubmission::findOrFail($submissionId);
            $updated = $this->ftaService->checkStatus($submission);

            return new SubmissionResult(
                success: true,
                submissionId: $updated->id,
                referenceId: $updated->reference,
                status: $updated->status->value,
                rawResponse: $updated->toArray(),
                errorMessage: null,
            );
        } catch (\Throwable $e) {
            return SubmissionResult::failure($e->getMessage());
        }
    }

    public function validate(Invoice $invoice, ComplianceProfile $profile): ValidationResult
    {
        $organization = $profile->org;

        try {
            $data = $this->ftaService->buildInvoiceData($invoice, $organization);
            $this->validator->validate($data);

            return ValidationResult::pass();
        } catch (FtaException $e) {
            return ValidationResult::fail([$e->getMessage()]);
        } catch (\Throwable $e) {
            return ValidationResult::fail([$e->getMessage()]);
        }
    }
}
