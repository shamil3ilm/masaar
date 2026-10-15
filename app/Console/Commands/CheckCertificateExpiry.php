<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\Branding\Services\ThemeService;
use App\Domains\Compliance\Fatoora\Helpers\CertificatePem;
use App\Domains\Compliance\Fatoora\Mail\CertificateExpiringMail;
use App\Domains\Compliance\Fatoora\Services\CertificateService;
use App\Domains\Compliance\Fatoora\Services\CredentialStore;
use App\Domains\Organization\Models\Organization;
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

        $organizations = $query->get()->filter(
            fn (Organization $org) => $this->credentials->has((string) $org->id, null, CredentialStore::PCSID)
        );

        if ($organizations->isEmpty()) {
            $this->warn('No organizations with certificates found.');

            return Command::SUCCESS;
        }

        $this->table(
            ['Organization', 'VAT Number', 'Expiry Date', 'Days Left', 'Status'],
            $organizations->map(function ($org) use ($shouldNotify) {
                return $this->checkOrganization($org, $shouldNotify);
            })
        );

        $this->info('');

        return Command::SUCCESS;
    }

    private function checkOrganization(Organization $organization, bool $shouldNotify): array
    {
        $certificate = $this->credentials->get(
            (string) $organization->id,
            null,
            CredentialStore::PCSID
        )['pcsid'] ?? null;
        $certificate = CertificatePem::from($certificate);

        if (! $certificate) {
            return [
                $organization->name,
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
                    'expired_on' => $expiryCarbon->toDateString(),
                ]);
            }

            // Send notifications if needed
            if ($shouldNotify) {
                $this->sendNotificationIfNeeded($organization, $daysRemaining, $expiryCarbon);
            }

            return [
                $organization->name,
                $organization->vat_number ?? 'N/A',
                $expiryCarbon->format('Y-m-d'),
                $daysRemaining,
                $status,
            ];

        } catch (\Exception $e) {
            Log::error('Certificate check failed', [
                'org_id' => $organization->id,
                'error' => $e->getMessage(),
            ]);

            return [
                $organization->name,
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

        // Check if notification already sent today
        $cacheKey = "cert_notify:{$organization->id}:{$daysRemaining}";
        if (cache()->has($cacheKey)) {
            return;
        }

        // Was an environment variable, so adding Slack meant a redeploy.
        $channels = $settings->get('certificates.channels');

        foreach ($channels as $channel) {
            match ($channel) {
                'mail' => $this->sendMailNotification($organization, $daysRemaining, $expiryDate),
                'webhook' => $this->sendWebhookNotification($organization, $daysRemaining, $expiryDate),
                'slack' => $this->sendSlackNotification($organization, $daysRemaining, $expiryDate),
                default => null,
            };
        }

        // Mark notification as sent for today
        cache()->put($cacheKey, true, now()->endOfDay());

        Log::info('Certificate expiry notification sent', [
            'org_id' => $organization->id,
            'days_remaining' => $daysRemaining,
            'channels' => $channels,
        ]);
    }

    private function sendMailNotification(
        Organization $organization,
        int $daysRemaining,
        Carbon $expiryDate
    ): void {
        // The organization's administrators, on the membership: role and
        // status are both the pivot's. Filtering on role alone mailed members
        // who had been removed.
        $adminEmails = $organization->users()
            ->wherePivot('role', 'admin')
            ->wherePivot('status', 'active')
            ->pluck('email')
            ->toArray();

        if (empty($adminEmails)) {
            return;
        }

        // In the organization's own branding: it is about its own filing.
        Mail::to($adminEmails)->send(new CertificateExpiringMail(
            organizationName: $organization->name,
            daysRemaining: $daysRemaining,
            expiryDate: $expiryDate->format('Y-m-d'),
            brand: $this->themes->forScope((string) $organization->id),
        ));
    }

    private function sendWebhookNotification(
        Organization $organization,
        int $daysRemaining,
        Carbon $expiryDate
    ): void {
        // Trigger webhook event for certificate expiry
        // This integrates with the existing webhook system
        event(new CertificateExpiring(
            organizationId: $organization->id,
            daysRemaining: $daysRemaining,
            expiryDate: $expiryDate->toIso8601String(),
        ));
    }

    private function sendSlackNotification(
        Organization $organization,
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
