<?php

declare(strict_types=1);

namespace App\Domains\Compliance;

use App\Domains\Compliance\Contracts\ComplianceEngine;
use App\Domains\Compliance\Contracts\SubmissionResult;
use App\Domains\Compliance\Contracts\ValidationResult;
use App\Domains\Compliance\Exceptions\UnsupportedJurisdictionException;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\ComplianceProfile;

class ComplianceRouter
{
    /** @param ComplianceEngine[] $engines */
    public function __construct(
        private readonly array $engines,
    ) {}

    /**
     * Resolve the engine that handles the given profile's jurisdiction.
     *
     * @throws UnsupportedJurisdictionException
     */
    public function engineFor(ComplianceProfile $profile): ComplianceEngine
    {
        foreach ($this->engines as $engine) {
            if ($engine->supports($profile->jurisdiction)) {
                return $engine;
            }
        }

        throw UnsupportedJurisdictionException::for($profile->jurisdiction);
    }

    /** @throws UnsupportedJurisdictionException */
    public function submit(Invoice $invoice, ComplianceProfile $profile): SubmissionResult
    {
        return $this->engineFor($profile)->submit($invoice, $profile);
    }

    /** @throws UnsupportedJurisdictionException */
    public function validate(Invoice $invoice, ComplianceProfile $profile): ValidationResult
    {
        return $this->engineFor($profile)->validate($invoice, $profile);
    }

    /** @throws UnsupportedJurisdictionException */
    public function retry(string $submissionId, ComplianceProfile $profile): SubmissionResult
    {
        return $this->engineFor($profile)->retry($submissionId, $profile);
    }

    /** @throws UnsupportedJurisdictionException */
    public function checkStatus(string $submissionId, ComplianceProfile $profile): SubmissionResult
    {
        return $this->engineFor($profile)->checkStatus($submissionId, $profile);
    }
}
