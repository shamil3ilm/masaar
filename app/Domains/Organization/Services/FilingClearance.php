<?php

declare(strict_types=1);

namespace App\Domains\Organization\Services;

use App\Domains\Compliance\Countries\SaudiArabia\Exceptions\FatooraException;
use App\Domains\Licensing\Services\LicenseValidationService;
use App\Domains\Organization\Models\Organization;
use App\Support\Errors\ErrorCode;

/**
 * Whether an organization may deal with its tax authority at all.
 *
 * One check for issuing, filing and onboarding, so they cannot disagree. A
 * business that signed itself up waits for a platform admin to approve it
 * and assign its plan; until then it can draft, and cannot obtain a
 * certificate, give an invoice its counter or send one to the authority.
 *
 * Every country passes it. It still throws FatooraException because callers
 * catch that type - UaeJurisdiction, SetupChecklist, the API's error
 * envelope - so changing it is a change to their contract, not a move.
 */
class FilingClearance
{
    public function __construct(
        private readonly LicenseValidationService $licences,
    ) {}

    /**
     * For new work with ZATCA: obtaining a certificate, and issuing.
     *
     * @throws FatooraException
     */
    public function assertCleared(Organization $organization): void
    {
        $this->assertMayFile($organization);

        if (config('licensing.filing_requires_licence') && ! $this->licences->organizationIsLicensed((string) $organization->id)) {
            throw new FatooraException(
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
     * @throws FatooraException
     */
    public function assertMayFile(Organization $organization): void
    {
        if ($organization->isSuspended()) {
            throw new FatooraException('Organization is suspended', ErrorCode::AUTH_ORGANIZATION_SUSPENDED);
        }

        if ($organization->isPendingReview()) {
            throw new FatooraException(
                __('This company is being reviewed. Connecting to e-invoicing and issuing open once it is approved.'),
                ErrorCode::AUTH_ORGANIZATION_UNDER_REVIEW,
            );
        }

        if ($organization->status === Organization::STATUS_REJECTED) {
            throw new FatooraException(
                __('This company was not approved to file. Contact support to review it again.'),
                ErrorCode::AUTH_ORGANIZATION_UNDER_REVIEW,
            );
        }
    }
}
