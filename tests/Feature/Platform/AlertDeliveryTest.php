<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Domains\Compliance\Fatoora\Jobs\ProcessFatooraSubmission;
use App\Domains\Compliance\Fatoora\Models\InvoiceSubmission;
use App\Domains\Compliance\Fatoora\Services\CredentialStore;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;
use Exception;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Spatie\Backup\Events\BackupHasFailed;
use Spatie\Backup\Events\UnhealthyBackupWasFound;
use Tests\Fixtures\SigningCredentials;
use Tests\TestCase;

/**
 * A critical event reaches someone, and the ones that matter are critical.
 *
 * Every Log::critical goes to the default stack, which always carries the
 * alerts channel: a file of its own, and Slack once LOG_SLACK_WEBHOOK_URL is
 * set. That plumbing was asserted nowhere. And two of the events an operator
 * most needs were logged below it: a submission that had exhausted its
 * retries - an invoice that will not be filed - and a tenant certificate that
 * had expired, so the tenant can file nothing at all.
 */
class AlertDeliveryTest extends TestCase
{
    use RefreshDatabase;
    use SigningCredentials;

    private string $alertFile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->alertFile = sys_get_temp_dir().'/masaar-alerts-'.uniqid().'.log';

        config([
            'logging.default' => 'stack',
            'logging.channels.stack.channels' => ['alerts'],
            'logging.channels.alerts.channels' => ['alert-file'],
            'logging.channels.alert-file.driver' => 'single',
            'logging.channels.alert-file.path' => $this->alertFile,
        ]);

        foreach (['stack', 'alerts', 'alert-file'] as $channel) {
            app('log')->forgetChannel($channel);
        }
    }

    protected function tearDown(): void
    {
        @unlink($this->alertFile);

        parent::tearDown();
    }

    public function test_a_critical_event_is_delivered(): void
    {
        Log::critical('Probe: the alert path delivers');

        $this->assertStringContainsString('Probe: the alert path delivers', $this->alerts());
    }

    public function test_a_warning_does_not_page(): void
    {
        Log::warning('Probe: only a warning');

        $this->assertStringNotContainsString('Probe: only a warning', $this->alerts());
    }

    /**
     * Slack joins the alert path the moment a webhook URL is configured.
     */
    public function test_slack_joins_once_configured(): void
    {
        $saved = $_SERVER['LOG_SLACK_WEBHOOK_URL'] ?? null;
        $_SERVER['LOG_SLACK_WEBHOOK_URL'] = $_ENV['LOG_SLACK_WEBHOOK_URL'] = 'https://hooks.slack.com/services/T/B/X';

        try {
            $logging = require base_path('config/logging.php');
        } finally {
            if ($saved === null) {
                unset($_SERVER['LOG_SLACK_WEBHOOK_URL'], $_ENV['LOG_SLACK_WEBHOOK_URL']);
            } else {
                $_SERVER['LOG_SLACK_WEBHOOK_URL'] = $_ENV['LOG_SLACK_WEBHOOK_URL'] = $saved;
            }
        }

        $this->assertContains('slack', $logging['channels']['alerts']['channels']);
        $this->assertContains('alerts', $logging['channels']['stack']['channels']);
    }

    /**
     * An invoice that will not be filed is an operator's business at once,
     * not when its deadline has passed.
     */
    public function test_a_permanent_failure_is_critical(): void
    {
        $organization = Organization::create(['name' => 'Acme Trading', 'country' => 'SA']);

        $invoice = Invoice::withoutTenantScope(fn () => Invoice::create([
            'org_id' => $organization->id,
            'invoice_number' => 'ACME-1',
            'type' => 'standard',
            'status' => 'issued',
            'issue_date' => now()->toDateString(),
            'currency' => 'SAR',
            'subtotal' => '100.00',
            'tax_amount' => '15.00',
            'total' => '115.00',
        ]));

        $submission = (new InvoiceSubmission)->forceFill([
            'invoice_id' => $invoice->id,
            'org_id' => $organization->id,
            // Not yet sent: a job that gave up on a sent submission leaves it
            // for reconciliation instead (FilingRetryTest).
            'state' => 'pending_submission',
            'submission_type' => 'clearance',
            'submission_mode' => 'async',
        ]);
        $submission->save();

        (new ProcessFatooraSubmission($submission))->failed(new RuntimeException('gateway gone'));

        $this->assertStringContainsString('permanently failed', $this->alerts());
    }

    /**
     * An expired certificate stops the tenant filing anything; the tenant was
     * told, the platform was not.
     */
    public function test_an_expired_certificate_is_critical(): void
    {
        Storage::fake('creds');
        config(['fatoora.signing.disk' => 'creds']);

        $organization = Organization::create(['name' => 'Acme Trading', 'country' => 'SA', 'vat_number' => '300000000000003']);

        // Valid for no days: expired as of today.
        $certificate = $this->selfSignedCredentials(days: 0)['certificate'];

        app(CredentialStore::class)->put($organization->id, null, CredentialStore::PCSID, [
            'pcsid' => $certificate,
            'secret' => 'device-secret',
        ]);

        $this->artisan('fatoora:check-certificate');

        $this->assertStringContainsString('certificate has expired', $this->alerts());
    }

    /**
     * A night without a backup is a night that cannot be restored.
     */
    public function test_a_failed_backup_is_critical(): void
    {
        event(new BackupHasFailed(new Exception('bucket unreachable'), 's3', 'Masaar'));

        $this->assertStringContainsString('Backup failed', $this->alerts());
        $this->assertStringContainsString('bucket unreachable', $this->alerts());
    }

    public function test_a_stale_backup_is_critical(): void
    {
        event(new UnhealthyBackupWasFound('s3', 'Masaar', collect([
            ['check' => 'MaximumAgeInDays', 'message' => 'The latest backup is too old'],
        ])));

        $this->assertStringContainsString('latest backup is too old', $this->alerts());
    }

    private function alerts(): string
    {
        return is_file($this->alertFile) ? (string) file_get_contents($this->alertFile) : '';
    }
}
