<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\SaudiArabia\Console;

use App\Domains\Branding\Services\ThemeService;
use App\Domains\Compliance\Countries\SaudiArabia\Mail\CertificateExpiringMail;
use App\Domains\Compliance\Countries\SaudiArabia\Signing\CertificatePem;
use App\Domains\Compliance\Countries\SaudiArabia\Signing\CertificateService;
use App\Domains\Compliance\Countries\SaudiArabia\Signing\CredentialStore;
use App\Domains\Organization\Enums\MemberRole;
use App\Domains\Organization\Models\EgsUnit;
use App\Domains\Organization\Models\Organization;
use App\Domains\Organization\Services\Tills;
use App\Domains\Platform\Services\Settings;
use App\Domains\Webhook\Events\CertificateExpiring;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Check ZATCA certificate expiry and send notifications.
 *
 * Run daily via scheduler to proactively alert organizations
 * before their certificates expire.
 *
 * Per till: each EGS unit is onboarded on its own and signs with a CSID of
 * its own (E-Invoicing Detailed Guidelines p10, p39), so head office's
 * certificate says nothing about a till's. Every till still in service -
 * active, or retiring and still filing what it signed - is checked, and
 * each organization's administrators are warned about each one.
 */
class CheckCertificateExpiry extends Command
{
    protected $signature = 'fatoora:check-certificate
                            {--organization= : Check specific organization only}
                            {--notify : Send notifications for expiring certificates}';

    protected $description = 'Check ZATCA certificate expiry and optionally send notifications';

    public function __construct(
        private readonly CertificateService $certificateService,
        private readonly CredentialStore $credentials,
        private readonly ThemeService $themes,
        private readonly Tills $tills,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->info('');
        $this->info('========================================');
        $this->info('  ZATCA Certificate Expiry Check');
        $this->info('========================================');
        $this->info('');

        $organizationId = $this->option('organization');
        $shouldNotify = $this->option('notify');

        // Certificates live in CredentialStore, encrypted, not on the
        // organization. This queried a zatca_certificate column that does not
        // exist, so the command threw before checking anything — every day
        // since it was scheduled.
        $query = Organization::query();

        if ($organizationId) {
            $query->where('id', $organizationId);
        }

        // Every till that holds a certificate: head office's under the
        // organization in the credential store, any other under its own id.
        $rows = [];

        foreach ($query->get() as $organization) {
            foreach ($this->tills->all((string) $organization->id) as $unit) {
                if (! $unit->files() || ! $this->credentials->has((string) $organization->id, $this->deviceKey($unit), CredentialStore::PCSID)) {
                    continue;
                }

                $rows[] = $this->checkTill($organization, $unit, $shouldNotify);
            }
        }

        if ($rows === []) {
            $this->warn('No organizations with certificates found.');

            return Command::SUCCESS;
        }

        $this->table(['Organization', 'Till', 'VAT Number', 'Expiry Date', 'Days Left', 'Status'], $rows);

        $this->info('');

        return Command::SUCCESS;
    }

    /** Where a till's credentials are kept: head office's under the organization itself. */
    private function deviceKey(EgsUnit $unit): ?string
    {
        return $unit->isHeadOffice() ? null : (string) $unit->id;
    }

    private function checkTill(Organization $organization, EgsUnit $unit, bool $shouldNotify): array
    {
        $till = $this->tills->label($unit);
        $certificate = $this->credentials->get(
            (string) $organization->id,
            $this->deviceKey($unit),
            CredentialStore::PCSID
        )['pcsid'] ?? null;
        $certificate = CertificatePem::from($certificate);

        if (! $certificate) {
            return [
                $organization->name,
                $till,
                $organization->vat_number ?? 'N/A',
                'N/A',
                'N/A',
                '❌ No certificate',
            ];
        }

        try {
            $expiryDate = $this->certificateService->getExpiryDate($certificate);

            if (! $expiryDate) {
                return [
                    $organization->name,
                    $till,
                    $organization->vat_number ?? 'N/A',
                    'Unknown',
                    'Unknown',
                    '⚠️ Cannot parse',
                ];
            }

            $expiryCarbon = Carbon::instance($expiryDate);
            $daysRemaining = (int) now()->diffInDays($expiryCarbon, false);

            $status = $this->getStatus($daysRemaining);

            // The tenant hears about it by mail, webhook or Slack as they have
            // chosen. The platform heard nothing, and an expired certificate
            // means that tenant can sign and file nothing at all. Daily while
            // it lasts, with this command.
            if ($daysRemaining <= 0) {
                Log::critical('A tenant certificate has expired; it cannot issue or file until it is renewed', [
                    'org_id' => $organization->id,
                    'device_id' => $unit->id,
                    'expired_on' => $expiryCarbon->toDateString(),
                ]);
            }

            // Send notifications if needed
            if ($shouldNotify) {
                $this->sendNotificationIfNeeded($organization, $unit, $till, $daysRemaining, $expiryCarbon);
            }

            return [
                $organization->name,
                $till,
                $organization->vat_number ?? 'N/A',
                $expiryCarbon->format('Y-m-d'),
                $daysRemaining,
                $status,
            ];

        } catch (\Exception $e) {
            Log::error('Certificate check failed', [
                'org_id' => $organization->id,
                'device_id' => $unit->id,
                'error' => $e->getMessage(),
            ]);

            return [
                $organization->name,
                $till,
                $organization->vat_number ?? 'N/A',
                'Error',
                'Error',
                '❌ '.$e->getMessage(),
            ];
        }
    }

    private function getStatus(int $daysRemaining): string
    {
        if ($daysRemaining <= 0) {
            return '🔴 EXPIRED';
        }

        if ($daysRemaining <= 7) {
            return '🔴 CRITICAL';
        }

        if ($daysRemaining <= 14) {
            return '🟠 WARNING';
        }

        if ($daysRemaining <= 30) {
            return '🟡 ATTENTION';
        }

        return '🟢 OK';
    }

    private function sendNotificationIfNeeded(
        Organization $organization,
        EgsUnit $unit,
        string $till,
        int $daysRemaining,
        Carbon $expiryDate
    ): void {
        $settings = app(Settings::class);

        // config('fatoora.certificate_notifications.enabled') existed and
        // nothing read it, so there was no way to turn these off short of
        // unscheduling the command.
        if (! $settings->get('certificates.notifications_enabled')) {
            return;
        }

        // A setting rather than a config literal: the schedule a taxpayer
        // wants warning on is an operational decision, and this one could
        // only be changed by shipping a release.
        $notifyAtDays = $settings->get('certificates.notify_at_days');

        if (! in_array($daysRemaining, $notifyAtDays) && $daysRemaining > 0) {
            return;
        }

        // Once a day per till.
        $cacheKey = "cert_notify:{$organization->id}:{$unit->id}:{$daysRemaining}";
        if (cache()->has($cacheKey)) {
            return;
        }

        // Was an environment variable, so adding Slack meant a redeploy.
        $channels = $settings->get('certificates.channels');

        foreach ($channels as $channel) {
            match ($channel) {
                'mail' => $this->sendMailNotification($organization, $till, $daysRemaining, $expiryDate),
                'webhook' => $this->sendWebhookNotification($organization, $unit, $daysRemaining, $expiryDate),
                'slack' => $this->sendSlackNotification($organization, $till, $daysRemaining, $expiryDate),
                default => null,
            };
        }

        // Mark notification as sent for today
        cache()->put($cacheKey, true, now()->endOfDay());

        Log::info('Certificate expiry notification sent', [
            'org_id' => $organization->id,
            'device_id' => $unit->id,
            'days_remaining' => $daysRemaining,
            'channels' => $channels,
        ]);
    }

    private function sendMailNotification(
        Organization $organization,
        string $till,
        int $daysRemaining,
        Carbon $expiryDate
    ): void {
        // The organization's administrators, on the membership: role and
        // status are both the pivot's. Filtering on role alone mailed members
        // who had been removed.
        $admins = $organization->users()
            ->wherePivotIn('role', MemberRole::ADMINISTERING)
            ->wherePivot('status', 'active')
            ->get();

        $brand = $this->themes->forScope((string) $organization->id);

        // In the organization's own branding: it is about its own filing.
        // One each, so each is written in its reader's language.
        foreach ($admins as $admin) {
            Mail::to($admin)->send(new CertificateExpiringMail(
                organizationName: $organization->name,
                daysRemaining: $daysRemaining,
                expiryDate: $expiryDate->format('Y-m-d'),
                brand: $brand,
                tillName: $till,
            ));
        }
    }

    private function sendWebhookNotification(
        Organization $organization,
        EgsUnit $unit,
        int $daysRemaining,
        Carbon $expiryDate
    ): void {
        // Trigger webhook event for certificate expiry
        // This integrates with the existing webhook system
        event(new CertificateExpiring(
            organizationId: $organization->id,
            daysRemaining: $daysRemaining,
            expiryDate: $expiryDate->toIso8601String(),
            deviceId: (string) $unit->id,
        ));
    }

    private function sendSlackNotification(
        Organization $organization,
        string $till,
        int $daysRemaining,
        Carbon $expiryDate
    ): void {
        $webhookUrl = config('logging.channels.slack.url');

        if (! $webhookUrl) {
            return;
        }

        $emoji = $daysRemaining <= 0 ? '🔴' : ($daysRemaining <= 7 ? '🟠' : '🟡');

        $payload = [
            'text' => "{$emoji} *ZATCA Certificate Alert*",
            'attachments' => [
                [
                    'color' => $daysRemaining <= 0 ? 'danger' : ($daysRemaining <= 7 ? 'warning' : '#ffcc00'),
                    'fields' => [
                        ['title' => 'Organization', 'value' => $organization->name, 'short' => true],
                        ['title' => 'Till', 'value' => $till, 'short' => true],
                        ['title' => 'VAT Number', 'value' => $organization->vat_number ?? 'N/A', 'short' => true],
                        ['title' => 'Expiry Date', 'value' => $expiryDate->format('Y-m-d'), 'short' => true],
                        ['title' => 'Days Remaining', 'value' => (string) $daysRemaining, 'short' => true],
                    ],
                ],
            ],
        ];

        \Http::post($webhookUrl, $payload);
    }
}
