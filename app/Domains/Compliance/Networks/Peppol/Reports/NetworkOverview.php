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

    /** Every state a count is shown for, in the order a document moves. */
    public const STATES = [
        DocumentStatus::READY, DocumentStatus::SENDING, DocumentStatus::SENT, DocumentStatus::DELIVERED,
        DocumentStatus::REPORTED, DocumentStatus::REJECTED, DocumentStatus::FAILED, DocumentStatus::RECEIVED,
    ];

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
     * @return array<string, int> documents per state, every state present
     */
    public function counts(): array
    {
        $counts = PeppolDocument::withoutTenantScope(fn () => PeppolDocument::query()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->all());

        return array_map(fn (string $state): int => (int) ($counts[$state] ?? 0), array_combine(self::STATES, self::STATES));
    }

    /**
     * Outbound documents not yet on their way, or stopped: newest first.
     *
     * @return Collection<int, PeppolDocument>
     */
    public function waiting(int $limit = 50): Collection
    {
        return PeppolDocument::withoutTenantScope(fn () => PeppolDocument::query()
            ->with('org:id,name')
            ->where('direction', PeppolDocument::DIRECTION_OUT)
            ->whereIn('status', self::WAITING)
            ->latest('updated_at')
            ->limit($limit)
            ->get(['id', 'org_id', 'status', 'attempts', 'last_error', 'errors', 'receiver_endpoint', 'updated_at']));
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
            ->get(['id', 'org_id', 'direction', 'status', 'document_type', 'asp_message_id', 'mls_code', 'sender_endpoint', 'receiver_endpoint', 'updated_at']));
    }
}
