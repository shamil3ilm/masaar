<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\Compliance\Zatca\Services\CertificateService;
use App\Domains\Organization\Models\Organization;
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
    protected $signature = 'zatca:check-certificate
                            {--organization= : Check specific organization only}
                            {--notify : Send notifications for expiring certificates}';

    protected $description = 'Check ZATCA certificate expiry and optionally send notifications';

    public function __construct(
        private readonly CertificateService $certificateService,
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

        $query = Organization::query()
            ->whereNotNull('zatca_certificate');

        if ($organizationId) {
            $query->where('id', $organizationId);
        }

        $organizations = $query->get();

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
        $certificate = $organization->zatca_certificate;

        if (!$certificate) {
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

            if (!$expiryDate) {
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
                'organization_id' => $organization->id,
                'error' => $e->getMessage(),
            ]);

            return [
                $organization->name,
                $organization->vat_number ?? 'N/A',
                'Error',
                'Error',
                '❌ ' . $e->getMessage(),
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
        $notifyAtDays = config('zatca.certificate_notifications.notify_at_days', [30, 14, 7, 3, 1]);

        if (!in_array($daysRemaining, $notifyAtDays) && $daysRemaining > 0) {
            return;
        }

        // Check if notification already sent today
        $cacheKey = "cert_notify:{$organization->id}:{$daysRemaining}";
        if (cache()->has($cacheKey)) {
            return;
        }

        $channels = config('zatca.certificate_notifications.channels', ['mail', 'webhook']);

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
            'organization_id' => $organization->id,
            'days_remaining' => $daysRemaining,
            'channels' => $channels,
        ]);
    }

    private function sendMailNotification(
        Organization $organization,
        int $daysRemaining,
        Carbon $expiryDate
    ): void {
        // Get admin email(s) for organization
        $adminEmails = $organization->users()
            ->where('role', 'admin')
            ->pluck('email')
            ->toArray();

        if (empty($adminEmails)) {
            return;
        }

        $subject = $daysRemaining <= 0
            ? "🔴 URGENT: ZATCA Certificate EXPIRED - {$organization->name}"
            : "⚠️ ZATCA Certificate Expiring in {$daysRemaining} days - {$organization->name}";

        $body = $daysRemaining <= 0
            ? "Your ZATCA certificate has EXPIRED. Invoice submissions will fail until renewed."
            : "Your ZATCA certificate will expire on {$expiryDate->format('Y-m-d')}. Please renew before expiry to avoid service interruption.";

        Mail::raw($body, function ($message) use ($adminEmails, $subject) {
            $message->to($adminEmails)
                ->subject($subject);
        });
    }

    private function sendWebhookNotification(
        Organization $organization,
        int $daysRemaining,
        Carbon $expiryDate
    ): void {
        // Trigger webhook event for certificate expiry
        // This integrates with the existing webhook system
        event(new \App\Domains\Webhook\Events\CertificateExpiring(
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

        if (!$webhookUrl) {
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
