<?php

declare(strict_types=1);

namespace Tests\Feature\Licensing;

use App\Domains\Licensing\Enums\LicenseTier;
use App\Domains\Licensing\Models\License;
use App\Domains\Licensing\Models\LicenseAuditLog;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Two administrators extending the same licence, and what the trail says.
 *
 * extend() computed its new expiry from $this->expires_at - a copy of the
 * licence loaded before changeWithAudit opened its transaction - and passed
 * changeWithAudit a fixed date. Because it also passed no $guard, the locked
 * re-read inside that transaction was skipped entirely, so nothing between
 * the two writes noticed the first.
 *
 * Both administrators read the same expiry, both computed it plus thirty
 * days, and both wrote that. The customer paid for sixty days and received
 * thirty, and license_audit_logs held two 'extended' rows each recording
 * days_added: 30 and the same resulting expiry - a trail that agreed with
 * neither the payment nor itself.
 *
 * Reproduced here with two instances of the same row rather than two
 * processes, because that is what the defect actually was: a value computed
 * from a copy loaded earlier. The lock matters on MySQL; the stale read
 * mattered everywhere.
 */
class LicenseExtendTest extends TestCase
{
    use RefreshDatabase;

    private License $license;

    protected function setUp(): void
    {
        parent::setUp();

        $organization = Organization::create([
            'name' => 'Acme Trading',
            'country' => 'SA',
            'vat_number' => '300000000000003',
        ]);

        $this->license = License::createWithCredentials([
            'org_id' => $organization->id,
            'organization_name' => 'Acme Trading',
            'contact_email' => 'erp@acme.test',
            'tier' => 'starter',
        ])['license'];
    }

    public function test_two_extensions_add_up(): void
    {
        $base = $this->expiringIn(10);

        // Loaded before either extension, which is the administrator who
        // opened the page first and pressed the button second.
        $stale = License::find($this->license->id);

        $this->license->extend(30);
        $stale->extend(30);

        $this->assertSame(
            $base->copy()->addDays(60)->toIso8601String(),
            $this->license->fresh()->expires_at->toIso8601String(),
            'one extension replaced the other, so the customer paid for sixty days and got thirty'
        );
    }

    /**
     * And the trail says what each one actually did.
     */
    public function test_the_audit_records_each_extension(): void
    {
        $base = $this->expiringIn(10);

        $stale = License::find($this->license->id);

        $this->license->extend(30);
        $stale->extend(30);

        $recorded = LicenseAuditLog::where('license_id', $this->license->id)
            ->where('event', 'extended')
            ->orderBy('created_at')
            ->pluck('new_values')
            ->map(fn ($values) => is_array($values) ? $values : json_decode((string) $values, true))
            ->pluck('expires_at')
            ->all();

        $this->assertSame([
            $base->copy()->addDays(30)->toIso8601String(),
            $base->copy()->addDays(60)->toIso8601String(),
        ], $recorded, 'both entries claimed the same expiry, so the trail cannot be reconciled');
    }

    /**
     * A tier change records the tier it actually left.
     */
    public function test_a_tier_change_records_its_start(): void
    {
        $stale = License::find($this->license->id);

        $this->license->upgradeTier(LicenseTier::Professional);
        $stale->upgradeTier(LicenseTier::Enterprise);

        $old = LicenseAuditLog::where('license_id', $this->license->id)
            ->where('event', 'tier_changed')
            ->orderBy('created_at')
            ->pluck('old_values')
            ->map(fn ($values) => is_array($values) ? $values : json_decode((string) $values, true))
            ->pluck('tier')
            ->all();

        $this->assertSame(
            ['starter', 'professional'],
            $old,
            'the second entry recorded a tier the licence had already left'
        );
    }

    /**
     * Set a known expiry to measure from, and hand it back.
     */
    private function expiringIn(int $days): Carbon
    {
        $base = now()->addDays($days)->startOfSecond();

        $this->license->forceFill(['expires_at' => $base])->save();

        return $base;
    }
}
