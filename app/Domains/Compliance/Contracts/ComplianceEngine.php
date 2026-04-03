<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Contracts;

use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\ComplianceProfile;

interface ComplianceEngine
{
    /** Jurisdiction ISO code this engine handles, e.g. 'SA', 'AE'. */
    public function supports(string $jurisdiction): bool;

    /** Submit invoice to the jurisdiction authority. */
    public function submit(Invoice $invoice, ComplianceProfile $profile): SubmissionResult;

    /** Retry a previously failed/rejected submission. */
    public function retry(string $submissionId, ComplianceProfile $profile): SubmissionResult;

    /** Poll the authority for an updated submission status. */
    public function checkStatus(string $submissionId, ComplianceProfile $profile): SubmissionResult;

    /** Validate invoice against jurisdiction rules without submitting. */
    public function validate(Invoice $invoice, ComplianceProfile $profile): ValidationResult;
}
