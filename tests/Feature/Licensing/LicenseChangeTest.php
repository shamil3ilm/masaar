<?php

declare(strict_types=1);

namespace Tests\Feature\Licensing;

use App\Domains\Licensing\Enums\LicenseTier;
use App\Domains\Licensing\Models\License;
use App\Domains\Licensing\Models\LicenseAuditLog;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A licence change and its audit entry are written together or not at all.
 *
 * A suspension with no audit entry is a change nobody can account for; the
 * entry is the record of who made it and why.
 */
class LicenseChangeTest extends TestCase
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

        LicenseAuditLog::creating(fn () => throw new \RuntimeException('audit store down'));
    }

    public function test_failed_audit_keeps_license_status(): void
    {
        $status = $this->license->fresh()->status;

        $this->assertThrows(fn () => $this->license->suspend('late payment'), \RuntimeException::class);

        $this->assertSame($status, $this->license->fresh()->status);
    }

    public function test_failed_audit_keeps_license_tier(): void
    {
        $tier = $this->license->fresh()->tier;

        $this->assertThrows(fn () => $this->license->upgradeTier(LicenseTier::Enterprise), \RuntimeException::class);

        $this->assertSame($tier, $this->license->fresh()->tier);
    }
}
