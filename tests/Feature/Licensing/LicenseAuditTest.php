<?php

declare(strict_types=1);

namespace Tests\Feature\Licensing;

use App\Domains\Licensing\Models\LicenseAuditLog;
use App\Domains\Licensing\Services\LicenseManagementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Licence administration has to leave a trail.
 *
 * It did not. logAudit() inserted action, details, performed_by and user_agent
 * straight onto license_audit_logs — four names the table does not have — so
 * every write threw, and a catch turned each one into a log line. The table has
 * been empty since it was created, and the endpoint that reads it mapped the
 * same absent names, so it returned nulls to match.
 *
 * A LicenseAuditLog model existed the whole time with the right columns and
 * append-only enforcement, and nothing used it.
 */
class LicenseAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_administration_is_recorded(): void
    {
        $service = app(LicenseManagementService::class);

        $issued = $service->createLicense([
            'organization_name' => 'Acme',
            'contact_email' => 'ops@acme.test',
            'tier' => 'starter',
        ]);

        $entry = LicenseAuditLog::where('license_id', $issued['license']->id)->first();

        $this->assertNotNull($entry, 'Creating a licence recorded nothing.');
        $this->assertSame('created', $entry->event);
        $this->assertSame('Acme', $entry->new_values['organization_name'] ?? null);
    }

    /**
     * Each administrative action appends rather than replacing, so the sequence
     * of what happened to a licence survives.
     */
    public function test_each_action_appends(): void
    {
        $service = app(LicenseManagementService::class);

        $license = $service->createLicense([
            'organization_name' => 'Acme',
            'contact_email' => 'ops@acme.test',
            'tier' => 'starter',
            'status' => 'active',
        ])['license'];

        $service->suspendLicense($license->id, 'non-payment');

        $events = LicenseAuditLog::where('license_id', $license->id)
            ->orderBy('created_at')
            ->pluck('event')
            ->all();

        $this->assertSame(['created', 'suspended'], $events);
    }

    /**
     * The reason for a suspension is the part an auditor needs, and it was
     * being written into a column that does not exist.
     */
    public function test_reason_is_kept(): void
    {
        $service = app(LicenseManagementService::class);

        $license = $service->createLicense([
            'organization_name' => 'Acme',
            'contact_email' => 'ops@acme.test',
            'tier' => 'starter',
            'status' => 'active',
        ])['license'];

        $service->suspendLicense($license->id, 'non-payment');

        $entry = LicenseAuditLog::where('license_id', $license->id)
            ->where('event', 'suspended')
            ->first();

        $this->assertSame('non-payment', $entry->new_values['reason'] ?? null);
    }

    /**
     * The read side names the columns that exist, so an auditor sees values
     * rather than a row of nulls.
     */
    public function test_audit_log_reads_back(): void
    {
        $service = app(LicenseManagementService::class);

        $license = $service->createLicense([
            'organization_name' => 'Acme',
            'contact_email' => 'ops@acme.test',
            'tier' => 'starter',
        ])['license'];

        $log = $service->getAuditLog($license->id);

        $this->assertNotEmpty($log);
        $this->assertSame('created', $log[0]['event']);
        $this->assertArrayNotHasKey('action', $log[0]);
    }
}
