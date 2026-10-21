<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance\SaudiArabia;

use App\Domains\Audit\Models\AuditLog;
use App\Domains\Compliance\Countries\SaudiArabia\Filing\UnfiledDocuments;
use App\Domains\Compliance\Countries\SaudiArabia\Pilot\Check;
use App\Domains\Compliance\Countries\SaudiArabia\Pilot\SimulationPreflight;
use App\Domains\Organization\Models\Organization;
use App\Domains\Platform\Services\Heartbeat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * platform:simulation-preflight: every check, failing on its own, says so
 * with a fix; all of them holding says ready; and nothing is changed.
 */
class SimulationPreflightTest extends TestCase
{
    use RefreshDatabase;

    private Organization $pilot;

    /** What the simulation gateway answers: its status and Date header, or no connection. */
    private int $status = 404;

    private ?string $date = null;

    private bool $connects = true;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('creds');
        config([
            'fatoora.signing.disk' => 'creds',
            'fatoora.endpoints.simulation' => 'https://zatca.test/simulation',
            'app.env' => 'production',
            'app.debug' => false,
            'mail.default' => 'smtp',
            'queue.default' => 'database',
        ]);

        $this->pilot = Organization::create([
            'name' => 'Pilot Trading',
            'name_ar' => 'شركة التجربة للتجارة',
            'country' => 'SA',
            'status' => 'active',
            'vat_number' => '310123456700003',
            'cr_number' => '1010101010',
            'street' => 'King Fahd Road',
            'building_number' => '1234',
            'district' => 'Al Olaya',
            'city' => 'Riyadh',
            'postal_code' => '12345',
        ]);

        $heartbeat = app(Heartbeat::class);
        $heartbeat->beat(Heartbeat::SCHEDULER);

        foreach (['default', 'zatca-submissions', 'webhooks'] as $queue) {
            $heartbeat->beat(Heartbeat::queue($queue));
        }

        Http::fake(function () {
            if (! $this->connects) {
                throw new ConnectionException('cURL error 7: Failed to connect');
            }

            return Http::response('', $this->status, ['Date' => $this->date ?? now()->toRfc7231String()]);
        });
    }

    public function test_ready_organization_passes_unchanged(): void
    {
        $audit = AuditLog::count();
        $before = $this->pilot->fresh()->toArray();

        $this->artisan('platform:simulation-preflight', ['organization' => $this->pilot->id])
            ->expectsOutputToContain('READY')
            ->assertSuccessful();

        $this->assertSame($before, $this->pilot->fresh()->toArray());
        $this->assertSame($audit, AuditLog::count());
        $this->assertNull(Cache::get('zatca:connectivity:status'), 'The default gateway\'s cached verdict was written.');
        $this->assertSame([], Storage::disk('creds')->allFiles());
    }

    public function test_found_by_its_vat_number(): void
    {
        $this->artisan('platform:simulation-preflight', ['organization' => '310123456700003'])->assertSuccessful();
    }

    public function test_pending_organization_is_not_ready(): void
    {
        $this->pilot->forceFill(['status' => 'pending_review'])->save();

        $this->assertFails('approved');
        $this->artisan('platform:simulation-preflight', ['organization' => $this->pilot->id])->assertFailed();
    }

    public function test_invalid_vat_number_fails(): void
    {
        $this->pilot->update(['vat_number' => '310123456700000']);

        $this->assertFails('vat');
    }

    public function test_missing_seller_id_fails(): void
    {
        $this->pilot->update(['cr_number' => null]);

        $this->assertFails('seller_id');
    }

    public function test_any_allowed_seller_scheme_passes(): void
    {
        $this->pilot->update(['cr_number' => 'LIC4471', 'compliance_profile' => ['seller_id_scheme' => 'MOM']]);

        $this->assertTrue($this->check('seller_id')->passed);
    }

    public function test_incomplete_address_fails(): void
    {
        $this->pilot->update(['district' => null, 'building_number' => '12']);

        $this->assertStringContainsString('district', $this->assertFails('address')->detail);
    }

    public function test_missing_arabic_name_fails(): void
    {
        $this->pilot->update(['name_ar' => 'Pilot Trading']);

        $this->assertFails('arabic_name');
    }

    public function test_unfiled_documents_block_the_switch(): void
    {
        $this->mock(UnfiledDocuments::class)->shouldReceive('countsFor')->andReturn(['device' => ['certificate' => 2]]);

        $this->assertStringContainsString('2 documents', $this->assertFails('unfiled')->detail);
    }

    public function test_log_mailer_fails(): void
    {
        config(['mail.default' => 'log']);

        $this->assertFails('mail');
    }

    public function test_test_mail_is_sent(): void
    {
        config(['mail.default' => 'array']);

        $check = $this->check('mail_send', 'ops@example.test');

        $this->assertTrue($check->passed, $check->detail);
        $this->assertCount(1, app('mailer')->getSymfonyTransport()->messages());
    }

    public function test_dead_worker_fails(): void
    {
        Cache::forget('platform:heartbeat:queue:zatca-submissions');

        $this->assertStringContainsString('zatca-submissions', $this->assertFails('queues')->detail);
    }

    public function test_sync_queue_fails(): void
    {
        config(['queue.default' => 'sync']);

        $this->assertFails('queues');
    }

    public function test_stale_scheduler_fails(): void
    {
        $this->travel(10)->minutes();

        $this->assertFails('scheduler');
    }

    public function test_unreachable_gateway_fails(): void
    {
        $this->connects = false;

        $this->assertFails('gateway');
        $this->assertFails('clock');
    }

    /** As the app judges it (Connectivity::reach()): any answer, a 5xx too, is a gateway that is there. */
    public function test_any_answer_is_reachable(): void
    {
        $this->status = 500;

        $this->assertTrue($this->check('gateway')->passed);
    }

    public function test_clock_drift_fails(): void
    {
        $this->date = now()->subMinute()->toRfc7231String();

        $this->assertFails('clock');
    }

    public function test_unwritable_storage_fails(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'preflight');
        config(['filesystems.disks.blocked' => ['driver' => 'local', 'root' => $file.'/zatca'], 'fatoora.signing.disk' => 'blocked']);

        $this->assertFails('storage');

        unlink($file);
    }

    public function test_debug_mode_fails(): void
    {
        config(['app.debug' => true]);

        $this->assertFails('app_env');
    }

    public function test_heartbeat_records_scheduler_and_queues(): void
    {
        Cache::flush();
        config(['queue.default' => 'sync']);

        $this->artisan('platform:heartbeat', ['--queue' => ['default', 'webhooks']])->assertSuccessful();

        $heartbeat = app(Heartbeat::class);
        $this->assertNotNull($heartbeat->last(Heartbeat::SCHEDULER));
        $this->assertNotNull($heartbeat->last(Heartbeat::queue('webhooks')));
        $this->assertNull($heartbeat->last(Heartbeat::queue('zatca-submissions')));
    }

    private function check(string $key, ?string $mailTo = null): Check
    {
        foreach (app(SimulationPreflight::class)->run($this->pilot->fresh(), $mailTo) as $check) {
            if ($check->key === $key) {
                return $check;
            }
        }

        $this->fail("No {$key} check.");
    }

    private function assertFails(string $key): Check
    {
        $check = $this->check($key);

        $this->assertFalse($check->passed, "{$key} passed: {$check->detail}");
        $this->assertNotSame('', $check->fix, "{$key} gives no fix.");

        return $check;
    }
}
