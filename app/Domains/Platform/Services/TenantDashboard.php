<?php

declare(strict_types=1);

namespace App\Domains\Platform\Services;

use App\Domains\Compliance\Fatoora\Services\CertificateService;
use App\Domains\Compliance\Fatoora\Services\ChainReport;
use App\Domains\Compliance\Fatoora\Services\CircuitBreaker;
use App\Domains\Compliance\Fatoora\Services\CredentialStore;
use App\Domains\Compliance\Fatoora\Services\QueueBacklog;
use App\Domains\Compliance\Fatoora\Services\SubmissionReport;
use App\Domains\Compliance\Peppol\Services\UaeDocuments;
use App\Domains\Invoice\Services\InvoiceReport;
use App\Domains\Organization\Models\Organization;

/**
 * One organization's dashboard, assembled from the domains that own each part.
 *
 * Every figure comes from a tenant-scoped report, so the caller must have
 * resolved the organization before asking. The id is taken only where a
 * lookup needs it by name: the credential store.
 */
class TenantDashboard
{
    /**
     * A queue with more stuck items than this is a warning.
     */
    private const STUCK_WARNING = 10;

    public function __construct(
        private readonly InvoiceReport $invoices,
        private readonly SubmissionReport $submissions,
        private readonly ChainReport $chain,
        private readonly QueueBacklog $backlog,
        private readonly CircuitBreaker $circuitBreaker,
        private readonly CertificateService $certificates,
        private readonly CredentialStore $credentials,
        private readonly UaeDocuments $uaeDocuments,
    ) {}

    /**
     * Invoices, submissions, chain and certificate at a glance.
     *
     * A UAE organization has no ZATCA submissions, chain or certificate: its
     * invoices go out as Peppol documents, counted as the portal dashboard
     * counts them.
     */
    public function overview(string $organizationId): array
    {
        if ($this->inUae($organizationId)) {
            return [
                'invoices' => $this->invoices->summary(),
                'e_invoices' => $this->uaeDocuments->counts($organizationId),
                'generated_at' => now()->toIso8601String(),
            ];
        }

        return [
            'invoices' => $this->invoices->summary(),
            'submissions' => $this->submissions->summary(),
            'compliance' => [
                'hash_chain_intact' => $this->chain->isIntact(),
                'latest_icv' => $this->chain->latestIcv(),
            ],
            'certificates' => $this->certificateSummary($organizationId),
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * The state of what submission depends on, and an overall verdict.
     */
    public function health(string $organizationId): array
    {
        if ($this->inUae($organizationId)) {
            return $this->uaeHealth($organizationId);
        }

        $backlog = $this->backlog->measure();

        $data = [
            'circuit_breaker' => $this->circuitBreaker->getMetrics('zatca_api'),
            'queue' => [
                'pending' => $backlog['pending'],
                'stuck' => $backlog['stuck'],
                'healthy' => $backlog['stuck'] === 0,
            ],
            'certificates' => $this->certificates->status($this->credentials->certificate($organizationId)),
            'checked_at' => now()->toIso8601String(),
        ];

        $data['status'] = $this->verdict($data);

        return $data;
    }

    /**
     * What became of the organization's filings: ZATCA submissions, or for a
     * UAE organization its e-invoices.
     */
    public function submissions(string $organizationId): array
    {
        return $this->inUae($organizationId)
            ? $this->uaeDocuments->counts($organizationId)
            : $this->submissions->summary();
    }

    /**
     * Invoices and submissions per day over the last given days, oldest first,
     * with every day present.
     */
    public function usage(int $days): array
    {
        $since = now()->subDays($days)->startOfDay();
        $invoices = $this->invoices->dailyCounts($since);
        $submissions = $this->submissions->dailyCounts($since);

        $dates = [];

        for ($i = $days - 1; $i >= 0; $i--) {
            $date = now()->subDays($i)->format('Y-m-d');
            $dates[] = [
                'date' => $date,
                'invoices' => $invoices[$date] ?? 0,
                'submissions' => $submissions[$date] ?? 0,
            ];
        }

        return [
            'period' => "{$days}d",
            'data' => $dates,
        ];
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
     * A UAE organization's e-invoices, and a verdict from them: a warning
     * while any was refused or given up on, since each needs someone to act.
     * The ZATCA circuit breaker, queue and certificate are not its.
     */
    private function uaeHealth(string $organizationId): array
    {
        $counts = $this->uaeDocuments->counts($organizationId);

        return [
            'e_invoices' => $counts,
            'checked_at' => now()->toIso8601String(),
            'status' => $counts['failed'] + $counts['rejected'] > 0 ? 'warning' : 'healthy',
        ];
    }

    private function inUae(string $organizationId): bool
    {
        return Organization::query()->whereKey($organizationId)->value('country') === 'AE';
    }

    /**
     * Critical when submission cannot work, warning when it soon may not.
     */
    private function verdict(array $data): string
    {
        if (($data['circuit_breaker']['state'] ?? 'closed') === 'open') {
            return 'critical';
        }

        $certificate = $data['certificates']['status'] ?? 'unknown';

        if (in_array($certificate, ['expired', 'missing', 'critical'], true)) {
            return 'critical';
        }

        if (($data['queue']['stuck'] ?? 0) > self::STUCK_WARNING) {
            return 'warning';
        }

        if ($certificate === 'warning') {
            return 'warning';
        }

        return 'healthy';
    }
}
