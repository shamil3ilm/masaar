<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Domains\Organization\Models\Branch;
use App\Domains\Organization\Models\ComplianceProfile;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Whether an organization has completed ZATCA onboarding is recorded in three
 * places, and the submission path refuses every invoice when the answer is no.
 *
 * Onboarding writes a flag into compliance_profile. A ComplianceProfile row is
 * the newer representation and is created by hand, defaulting to
 * pending_onboarding. Branches carry their own status.
 *
 * The accessor asked complianceProfileFor(), which returns only active
 * profiles — so a suspended or revoked one came back as no profile at all, the
 * answer fell through to the flag onboarding had written, and stopping a
 * jurisdiction stopped nothing. Nothing transitions a profile to those states
 * today, so this was a control that did not work rather than one that failed
 * in production; it is declared, and it should mean what it says.
 *
 * A profile that says active means yes. Suspended or revoked means no, because
 * that is a decision to stop and it outranks older evidence. Waiting is not a
 * decision, so it says nothing and the question falls through to what
 * onboarding actually recorded.
 */
class OnboardedStateTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::create(['name' => 'Acme', 'country' => 'SA']);
    }

    public function test_completed_onboarding_counts(): void
    {
        $this->onboard();

        $this->assertTrue($this->organization->fresh()->zatca_onboarded);
    }

    /**
     * Profiles default to pending_onboarding, so adding one to an organization
     * that had already onboarded must not retract it.
     */
    public function test_pending_profile_keeps_it(): void
    {
        $this->onboard();
        $this->profile(ComplianceProfile::STATUS_PENDING);

        $this->assertTrue(
            $this->organization->fresh()->zatca_onboarded,
            'Adding a compliance profile stopped an onboarded organization filing.'
        );
    }

    public function test_an_active_profile_counts(): void
    {
        $this->profile(ComplianceProfile::STATUS_ACTIVE);

        $this->assertTrue($this->organization->fresh()->zatca_onboarded);
    }

    /**
     * Suspension is deliberate and outranks anything recorded earlier.
     */
    public function test_a_suspended_profile_stops_it(): void
    {
        $this->onboard();
        $this->profile(ComplianceProfile::STATUS_SUSPENDED);

        $this->assertFalse($this->organization->fresh()->zatca_onboarded);
    }

    public function test_a_revoked_profile_stops_it(): void
    {
        $this->onboard();
        $this->profile(ComplianceProfile::STATUS_REVOKED);

        $this->assertFalse($this->organization->fresh()->zatca_onboarded);
    }

    /**
     * An organization that has done nothing has not onboarded.
     */
    public function test_nothing_recorded_means_no(): void
    {
        $this->assertFalse($this->organization->fresh()->zatca_onboarded);
    }

    /**
     * A working branch is the third way of answering yes, and it is what a
     * taxpayer who onboarded per location has.
     */
    public function test_an_active_branch_counts(): void
    {
        Branch::withoutTenantScope(fn () => Branch::create([
            'org_id' => $this->organization->id,
            'name' => 'Jeddah',
            'device_serial' => 'EGS-'.uniqid(),
            'industry' => 'Retail',
            'street' => 'Corniche Road',
            'building_number' => '4321',
            'district' => 'Al Hamra',
            'city' => 'Jeddah',
            'postal_code' => '23234',
            'onboarding_status' => Branch::STATUS_ACTIVE,
        ]));

        $this->assertTrue($this->organization->fresh()->zatca_onboarded);
    }

    /**
     * What requestPcsid() writes when the authority issues a production CSID.
     */
    private function onboard(): void
    {
        $this->organization->update([
            'compliance_profile' => array_merge(
                $this->organization->compliance_profile ?? [],
                ['zatca_onboarded' => true, 'onboarded_at' => now()->toISOString()]
            ),
        ]);
    }

    private function profile(string $status): void
    {
        ComplianceProfile::withoutTenantScope(fn () => ComplianceProfile::create([
            'org_id' => $this->organization->id,
            'jurisdiction' => 'SA',
            'engine' => 'fatoora',
            'status' => $status,
        ]));
    }
}
