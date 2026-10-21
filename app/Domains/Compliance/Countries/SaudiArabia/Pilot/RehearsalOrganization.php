<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\SaudiArabia\Pilot;

use App\Domains\Compliance\Countries\SaudiArabia\Onboarding\EnvironmentSwitch;
use App\Domains\Compliance\Countries\SaudiArabia\Rules\FatooraEnvironment;
use App\Domains\Licensing\Enums\LicenseTier;
use App\Domains\Organization\Models\Organization;
use App\Domains\Organization\Services\OrganizationReview;
use App\Domains\Organization\Services\OrganizationStatus;
use DomainException;

/**
 * The one organization platform:rehearse works with, and only it.
 *
 * Found by the flag in its compliance profile, never by its VAT number:
 * the developer portal's certificate is issued for one shared test VAT
 * number, which a real organization on a test deployment may well carry
 * too. Created the way a business that signed up is - pending, then
 * approved by the platform admin running the rehearsal, then set to the
 * sandbox - so the approval and the environment switch are audited like
 * any other.
 *
 * Its Arabic legal name has a teh marbuta (ة) in it on purpose: the QR and
 * the XML must carry the registered name as it is written, and a name
 * without one cannot show that it was altered.
 *
 * Between rehearsals it is suspended with a reason that says what it is,
 * so nothing is issued under it by accident; everything it issued is kept
 * (records are never deleted: ZATCA Detailed Guidelines p26, p63).
 */
class RehearsalOrganization
{
    /** The compliance profile flag that marks it. */
    public const PROFILE_KEY = 'rehearsal';

    /** The taxpayer the developer portal's certificate is issued for (fatoora:sandbox-filing). */
    public const VAT_NUMBER = '399999999900003';

    public const NAME = 'Rehearsal Trading Company (test only, not a taxpayer)';

    public const NAME_AR = 'شركة التجربة التجارية';

    /** Why it is suspended between rehearsals, as the console shows it. */
    public const SUSPENSION_REASON = 'Rehearsal organization for platform:rehearse. Not a taxpayer: everything it issued went to the ZATCA sandbox and is kept. The next rehearsal reactivates it.';

    public function __construct(
        private readonly OrganizationReview $review,
        private readonly OrganizationStatus $status,
        private readonly EnvironmentSwitch $environment,
    ) {}

    public function find(): ?Organization
    {
        return Organization::query()
            ->where('compliance_profile->'.self::PROFILE_KEY, true)
            ->oldest()
            ->first();
    }

    /**
     * The rehearsal organization, active and in the sandbox: made on the
     * first run, reactivated on later ones.
     *
     * @throws DomainException when it works in any environment but the sandbox
     */
    public function prepare(string $adminId): Organization
    {
        $organization = $this->find() ?? $this->create($adminId);

        $environment = FatooraEnvironment::of($organization);

        if ($environment !== FatooraEnvironment::SANDBOX) {
            throw new DomainException(__('The rehearsal organization works in the :environment environment. A rehearsal runs only against the sandbox: set it back to the sandbox on its ZATCA environment page first.', ['environment' => FatooraEnvironment::label($environment)]));
        }

        if ($organization->isSuspended()) {
            $organization = $this->status->reactivate((string) $organization->id, $adminId);
        }

        return $organization->fresh() ?? $organization;
    }

    /**
     * Suspended until the next rehearsal, its records kept.
     */
    public function close(Organization $organization, string $adminId): void
    {
        if ($organization->fresh()?->status === Organization::STATUS_ACTIVE) {
            $this->status->suspend((string) $organization->id, self::SUSPENSION_REASON, $adminId);
        }
    }

    private function create(string $adminId): Organization
    {
        $organization = Organization::create([
            'name' => self::NAME,
            'name_ar' => self::NAME_AR,
            'country' => 'SA',
            'status' => Organization::STATUS_PENDING_REVIEW,
            'vat_number' => self::VAT_NUMBER,
            'cr_number' => '1010010000',
            'street' => 'King Fahd Road',
            'building_number' => '1234',
            'district' => 'Al Olaya',
            'city' => 'Riyadh',
            'postal_code' => '12345',
            'prices_include_vat' => false,
            'compliance_profile' => [self::PROFILE_KEY => true],
        ]);

        // A plan that outlasts the free month, so a rehearsal next year is
        // not refused for want of one.
        $this->review->approve((string) $organization->id, $adminId, LicenseTier::Professional->value, now()->addYears(10)->toDateTimeString());

        // The sandbox, whatever this deployment's default is.
        $this->environment->set($organization->refresh(), FatooraEnvironment::SANDBOX, $adminId);

        return $organization->refresh();
    }
}
