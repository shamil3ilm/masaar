<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\SaudiArabia\Reports;

use App\Domains\Compliance\Countries\SaudiArabia\Filing\CircuitBreaker;
use App\Domains\Compliance\Countries\SaudiArabia\Signing\CertificateService;
use App\Domains\Compliance\Countries\SaudiArabia\Signing\CredentialStore;
use App\Domains\Compliance\Engine\Contracts\Dashboard as DashboardPort;
use App\Domains\Compliance\Engine\DTOs\Panel;
use App\Domains\Invoice\Services\InvoiceReport;
use App\Domains\Organization\Models\Organization;
use Carbon\CarbonInterface;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;

/**
 * A Saudi organization's dashboard: its ZATCA submissions, the hash chain,
 * the offline queue and the certificate it signs with.
 *
 * Every figure comes from a tenant-scoped report, so the caller has resolved
 * the organization before asking. The id is taken only where a lookup needs
 * it by name: the credential store.
 */
class Dashboard implements DashboardPort
{
    /** A queue with more stuck items than this is a warning. */
    private const STUCK_WARNING = 10;

    public function __construct(
        private readonly InvoiceReport $invoices,
        private readonly SubmissionReport $submissions,
        private readonly ChainReport $chain,
        private readonly QueueBacklog $backlog,
        private readonly CircuitBreaker $circuitBreaker,
        private readonly CertificateService $certificates,
        private readonly CredentialStore $credentials,
    ) {}

    public function heading(): string
    {
        return __('Your ZATCA compliance overview');
    }

    public function portal(Organization $organization): Panel
    {
        return new Panel('countries.saudi-arabia.dashboard', [
            'stats' => [
                'invoices_today' => $this->invoices->countSince(now()->startOfDay()),
                'invoices_month' => $this->invoices->countSince(now()->startOfMonth()),
                ...Arr::only($this->submissions->summary(), ['cleared', 'reported', 'rejected', 'pending']),
            ],
            'certificate' => $this->activeCertificate((string) $organization->id),
            'userActivity' => $this->submissions->activityByUser(now()->subDays(7), 10),
            'recentSubmissions' => $this->submissions->recent(10),
        ]);
    }

    /** Submissions, the chain and the certificate at a glance. */
    public function overview(string $organizationId): array
    {
        return [
            'submissions' => $this->submissions->summary(),
            'compliance' => [
                'hash_chain_intact' => $this->chain->isIntact(),
                'latest_icv' => $this->chain->latestIcv(),
            ],
            'certificates' => $this->certificateSummary($organizationId),
        ];
    }

    /**
     * The state of what submission depends on: the circuit breaker, the
     * offline queue and the certificate.
     */
    public function health(string $organizationId): array
    {
        $backlog = $this->backlog->measure();

        $checks = [
            'circuit_breaker' => $this->circuitBreaker->getMetrics('zatca_api'),
            'queue' => [
                'pending' => $backlog['pending'],
                'stuck' => $backlog['stuck'],
                'healthy' => $backlog['stuck'] === 0,
            ],
            'certificates' => $this->certificates->status($this->credentials->certificate($organizationId)),
        ];

        return ['checks' => $checks, 'status' => $this->verdict($checks)];
    }

    public function filings(string $organizationId): array
    {
        return $this->submissions->summary();
    }

    public function dailyFilings(CarbonInterface $since): array
    {
        return $this->submissions->dailyCounts($since);
    }

    public function activity(int $limit): Collection
    {
        return $this->submissions->activity($limit);
    }

    /**
     * The certificate this organization signs with, or null before onboarding.
     *
     * Read from the credential store, which is where onboarding writes. The
     * store holds the certificate an organization currently signs with and no
     * history of previous ones, so there is nothing further to show.
     */
    public function activeCertificate(string $organizationId): ?object
    {
        $details = $this->certificates->details(
            $this->credentials->certificate($organizationId)
        );

        return $details === null ? null : (object) $details;
    }

    /**
     * Whether the organization holds a certificate, and how long it has left.
     *
     * The platform keeps the certificate an organization signs with, not a
     * history of them, so there is no count of certificates held or invoices
     * signed per certificate to report.
     *
     * @return array{active: bool, days_until_expiry: ?int}
     */
    private function certificateSummary(string $organizationId): array
    {
        $status = $this->certificates->status($this->credentials->certificate($organizationId));

        return [
            'active' => $status['status'] !== 'missing',
            'days_until_expiry' => $status['days_remaining'] ?? null,
        ];
    }

    /**
     * Critical when submission cannot work, warning when it soon may not.
     *
     * @param  array<string, mixed>  $checks
     */
    private function verdict(array $checks): string
    {
        if (($checks['circuit_breaker']['state'] ?? 'closed') === 'open') {
            return 'critical';
        }

        $certificate = $checks['certificates']['status'] ?? 'unknown';

        if (in_array($certificate, ['expired', 'missing', 'critical'], true)) {
            return 'critical';
        }

        if (($checks['queue']['stuck'] ?? 0) > self::STUCK_WARNING) {
            return 'warning';
        }

        if ($certificate === 'warning') {
            return 'warning';
        }

        return 'healthy';
    }
}
