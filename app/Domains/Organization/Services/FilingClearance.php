<?php

declare(strict_types=1);

namespace App\Domains\Organization\Services;

use App\Domains\Licensing\Services\LicenseValidationService;
use App\Domains\Organization\Models\Organization;
use App\Support\Errors\ComplianceException;
use App\Support\Errors\ErrorCode;

/**
 * Whether an organization may deal with its tax authority at all.
 *
 * One check for issuing, filing and onboarding, so they cannot disagree. A
 * business that signed itself up waits for a platform admin to approve it
 * and assign its plan; until then it can draft, and cannot obtain a
 * certificate, give an invoice its counter or send one to the authority.
 *
 * Every country passes it, so it throws the neutral ComplianceException,
 * which each country's step, SetupChecklist and the API's error envelope
 * catch; the error codes and statuses are the taxonomy's.
 */
class FilingClearance
{
    public function __construct(
        private readonly LicenseValidationService $licences,
    ) {}

    /**
     * For new work with the authority: obtaining a certificate, and issuing.
     *
     * @throws ComplianceException
     */
    public function assertCleared(Organization $organization): void
    {
        $this->assertMayFile($organization);
        $this->assertLicensed($organization);
    }

    /**
     * On a plan, when this deployment requires one for new work. Access asks
     * this on its own, as the plan level, ahead of the organization's status.
     *
     * @throws ComplianceException
     */
    public function assertLicensed(Organization $organization): void
    {
        if (config('licensing.filing_requires_licence') && ! $this->licences->organizationIsLicensed((string) $organization->id)) {
            throw new ComplianceException(
                __('This company has no active plan. A platform admin assigns one when approving it.'),
                ErrorCode::AUTH_LICENSE_REQUIRED,
            );
        }
    }

    /**
     * For sending an invoice already issued.
     *
     * Not the plan: an issued invoice is a tax invoice, and reporting or
     * clearing it is the taxpayer's obligation whether or not its plan has
     * since lapsed. Withholding it would make the lapse their compliance
     * failure.
     *
     * @throws ComplianceException
     */
    public function assertMayFile(Organization $organization): void
    {
        if ($organization->isSuspended()) {
            throw new ComplianceException('Organization is suspended', ErrorCode::AUTH_ORGANIZATION_SUSPENDED);
        }

        if ($organization->isPendingReview()) {
            throw new ComplianceException(
                __('This company is being reviewed. Connecting to e-invoicing and issuing open once it is approved.'),
                ErrorCode::AUTH_ORGANIZATION_UNDER_REVIEW,
            );
        }

        if ($organization->status === Organization::STATUS_REJECTED) {
            throw new ComplianceException(
                __('This company was not approved to file. Contact support to review it again.'),
                ErrorCode::AUTH_ORGANIZATION_UNDER_REVIEW,
            );
        }
    }
}
