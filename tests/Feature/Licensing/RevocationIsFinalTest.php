<?php

declare(strict_types=1);

namespace Tests\Feature\Licensing;

use App\Domains\Auth\Models\User;
use App\Domains\Licensing\Enums\LicenseStatus;
use App\Domains\Licensing\Enums\LicenseTier;
use App\Domains\Licensing\Models\License;
use App\Domains\Licensing\Services\LicenseManagementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * A revoked licence stays revoked.
 *
 * `LicenseStatus::canReactivate()` says which statuses may come back -
 * Suspended and Expired, not Revoked - and nothing in the application called
 * it. `License::reactivate()` set the status to Active unconditionally, so a
 * licence revoked for fraud, breach or non-payment could be restored by anyone
 * who could reach `reactivateLicense()`, which is the admin console and
 * `POST /licensing/{id}/reactivate` on the API.
 *
 * Suspension is the reversible one and exists for exactly this. Revocation is
 * the decision you do not want undone by a misclick, so it is refused and the
 * remedy is to issue a new licence - which leaves a record that one was issued.
 */
class RevocationIsFinalTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_revoked_licence_stays_revoked(): void
    {
        $licence = $this->licence(LicenseStatus::Revoked);

        $this->expectException(RuntimeException::class);

        try {
            app(LicenseManagementService::class)->reactivateLicense($licence->id);
        } finally {
            $this->assertSame(
                LicenseStatus::Revoked,
                $licence->fresh()->status,
                'A revoked licence was brought back to life.'
            );
        }
    }

    /**
     * Suspension is the reversible one, and must stay reversible - refusing
     * everything would be as wrong as refusing nothing.
     */
    public function test_a_suspended_licence_comes_back(): void
    {
        $licence = $this->licence(LicenseStatus::Suspended);

        app(LicenseManagementService::class)->reactivateLicense($licence->id);

        $this->assertSame(LicenseStatus::Active, $licence->fresh()->status);
    }

    /**
     * And it cannot be walked back in two steps either.
     *
     * Guarding reactivate() alone was not enough: suspend() had no status
     * check, so a revoked licence could be suspended - which is a status
     * canReactivate() accepts - and then reactivated. Three steps from
     * terminal to Active, each one individually permitted.
     *
     * Found in review, not by the first guard's own test, because that test
     * asked whether revoked could be reactivated directly and the answer was
     * honestly no.
     */
    public function test_revoked_cannot_be_suspended(): void
    {
        $licence = $this->licence(LicenseStatus::Revoked);

        $this->expectException(RuntimeException::class);

        try {
            app(LicenseManagementService::class)->suspendLicense($licence->id, 'trying it on');
        } finally {
            $this->assertSame(
                LicenseStatus::Revoked,
                $licence->fresh()->status,
                'A revoked licence was moved into a reactivatable status.'
            );
        }
    }

    /**
     * The audit row names the person, not "system".
     *
     * Nothing in the console passed an actor, so the three actions worth
     * asking "who did this" about were the unattributed ones, while create
     * and rotate were attributed because logAudit() already fell back this way.
     */
    public function test_an_audit_row_names_the_actor(): void
    {
        $user = User::factory()->create(['is_platform_admin' => true]);
        $licence = $this->licence(LicenseStatus::Active);

        $this->actingAs($user);

        app(LicenseManagementService::class)->suspendLicense($licence->id, 'non-payment');

        $this->assertDatabaseHas('license_audit_logs', [
            'license_id' => $licence->id,
            'event' => 'suspended',
            'actor_type' => 'user',
            'actor_id' => $user->id,
        ]);
    }

    private function licence(LicenseStatus $status): License
    {
        return License::createWithCredentials([
            'organization_name' => 'Partner '.$status->value,
            'contact_email' => 'ops@partner.test',
            'tier' => LicenseTier::Professional,
            'status' => $status,
        ])['license'];
    }
}
