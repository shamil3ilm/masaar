<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Peppol;

use App\Domains\Compliance\Contracts\ComplianceEngine;
use App\Domains\Compliance\Contracts\SubmissionResult;
use App\Domains\Compliance\Contracts\ValidationResult;
use App\Domains\Compliance\Peppol\Models\PeppolDocument;
use App\Domains\Compliance\Peppol\Services\PeppolPreparer;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\ComplianceProfile;
use DomainException;

/**
 * The UAE behind the compliance router.
 *
 * There is no authority to submit to: a UAE invoice becomes a Peppol
 * document, and an accredited service provider carries it. So "submit"
 * here prepares and stores the document in status 'ready', where the
 * transport picks it up; the submission id is the document's id, and its
 * status is whatever the transport has since recorded on it.
 */
class PeppolEngine implements ComplianceEngine
{
    public function __construct(
        private readonly PeppolPreparer $preparer,
    ) {}

    public function supports(string $jurisdiction): bool
    {
        return $jurisdiction === 'AE';
    }

    public function submit(Invoice $invoice, ComplianceProfile $profile): SubmissionResult
    {
        try {
            return $this->result($this->preparer->prepare($invoice));
        } catch (DomainException $e) {
            return SubmissionResult::failure($e->getMessage(), ['problems' => $this->preparer->problems($invoice)]);
        }
    }

    /**
     * Sending again is the transport's: a stored document is already valid
     * and is never rebuilt. This reports where it stands.
     */
    public function retry(string $submissionId, ComplianceProfile $profile): SubmissionResult
    {
        return $this->checkStatus($submissionId, $profile);
    }

    public function checkStatus(string $submissionId, ComplianceProfile $profile): SubmissionResult
    {
        $document = PeppolDocument::query()
            ->whereKey($submissionId)
            ->where('org_id', $profile->org_id)
            ->first();

        return $document === null
            ? SubmissionResult::failure(__('No UAE e-invoice with that id.'))
            : $this->result($document);
    }

    public function validate(Invoice $invoice, ComplianceProfile $profile): ValidationResult
    {
        $problems = $this->preparer->problems($invoice);

        return $problems === [] ? ValidationResult::pass() : ValidationResult::fail($problems);
    }

    private function result(PeppolDocument $document): SubmissionResult
    {
        $failed = in_array($document->status, ['failed', 'rejected'], true);

        return new SubmissionResult(
            success: ! $failed,
            submissionId: (string) $document->id,
            referenceId: $document->asp_message_id,
            status: (string) $document->status,
            rawResponse: ['mls_code' => $document->mls_code, 'errors' => $document->errors],
            errorMessage: $failed ? (string) ($document->last_error ?? '') : null,
        );
    }
}
