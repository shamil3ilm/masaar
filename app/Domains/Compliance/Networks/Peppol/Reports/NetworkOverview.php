<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Networks\Peppol\Reports;

use App\Domains\Compliance\Networks\Peppol\Models\PeppolDocument;
use App\Domains\Compliance\Networks\Peppol\NetworkServiceProvider;
use App\Domains\Compliance\Networks\Peppol\Sending\DocumentStatus;
use Illuminate\Support\Collection;

/**
 * The Peppol network as the admin console's Peppol page shows it: what
 * carries documents, how many are where, and which wait on someone.
 *
 * Across every tenant by design, as the console's other reports are, so it
 * reads without the tenant scope.
 */
final class NetworkOverview
{
    /** Outbound states a person may have to act on. */
    public const WAITING = [DocumentStatus::READY, DocumentStatus::SENDING, DocumentStatus::FAILED, DocumentStatus::REJECTED];

    /**
     * Every state a count is shown for, in the order a document moves:
     * delivery, then the report to the authority, a track of its own.
     */
    public const STATES = [
        DocumentStatus::READY, DocumentStatus::SENDING, DocumentStatus::SENT, DocumentStatus::DELIVERED,
        DocumentStatus::NOT_EXCHANGED, DocumentStatus::REJECTED, DocumentStatus::FAILED, DocumentStatus::RECEIVED,
    ];

    public const REPORTING_STATES = [DocumentStatus::PENDING, DocumentStatus::REPORTED, DocumentStatus::REPORT_FAILED];

    /**
     * What documents are handed to, from configuration alone.
     *
     * @return array{label: string, tone: string}
     */
    public function gateway(): array
    {
        return match (true) {
            config('peppol.gateway') === NetworkServiceProvider::SIMULATOR => [
                'label' => 'Simulator, not for production: documents are checked against the published rules and answered here, and nothing reaches the network',
                'tone' => 'warning',
            ],
            filled(config('peppol.asp.base_url')) => ['label' => 'Service provider connected', 'tone' => 'success'],
            default => ['label' => 'No service provider: documents wait to be uploaded by hand', 'tone' => 'neutral'],
        };
    }

    /**
     * @return array<string, int> documents per state, every state of both tracks present
     */
    public function counts(): array
    {
        $count = fn (string $column): array => PeppolDocument::withoutTenantScope(fn () => PeppolDocument::query()
            ->selectRaw("{$column} as state, count(*) as total")
            ->whereNotNull($column)
            ->groupBy($column)
            ->pluck('total', 'state')
            ->all());

        $delivery = $count('status');
        $reporting = $count('reporting_status');

        return [
            ...array_map(fn (string $state): int => (int) ($delivery[$state] ?? 0), array_combine(self::STATES, self::STATES)),
            ...array_map(fn (string $state): int => (int) ($reporting[$state] ?? 0), array_combine(self::REPORTING_STATES, self::REPORTING_STATES)),
        ];
    }

    /**
     * Outbound documents not yet on their way, stopped, or whose report to
     * the authority failed: newest first.
     *
     * @return Collection<int, PeppolDocument>
     */
    public function waiting(int $limit = 50): Collection
    {
        return PeppolDocument::withoutTenantScope(fn () => PeppolDocument::query()
            ->with('org:id,name')
            ->where('direction', PeppolDocument::DIRECTION_OUT)
            ->where(fn ($query) => $query
                ->whereIn('status', self::WAITING)
                ->orWhere('reporting_status', DocumentStatus::REPORT_FAILED))
            ->latest('updated_at')
            ->limit($limit)
            ->get(['id', 'org_id', 'status', 'reporting_status', 'attempts', 'last_error', 'errors', 'reporting_errors', 'receiver_endpoint', 'updated_at']));
    }

    /**
     * The latest documents either way: newest first.
     *
     * @return Collection<int, PeppolDocument>
     */
    public function latest(int $limit = 25): Collection
    {
        return PeppolDocument::withoutTenantScope(fn () => PeppolDocument::query()
            ->with('org:id,name')
            ->latest('updated_at')
            ->limit($limit)
            ->get(['id', 'org_id', 'direction', 'status', 'reporting_status', 'document_type', 'asp_message_id', 'mls_code', 'sender_endpoint', 'receiver_endpoint', 'updated_at']));
    }
}
