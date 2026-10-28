<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Networks\Peppol\Sending;

use App\Domains\Compliance\Networks\Peppol\Exceptions\AspRefusedException;
use App\Domains\Compliance\Networks\Peppol\Exceptions\AspUnavailableException;
use App\Domains\Compliance\Networks\Peppol\Exceptions\NoAspConfiguredException;
use App\Domains\Compliance\Networks\Peppol\Gateways\AspGatewayResolver;
use App\Domains\Compliance\Networks\Peppol\Models\PeppolDocument;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Asks the provider to report a document again after its report to the
 * authority failed.
 *
 * The taxpayer must make sure every document's tax data reaches the
 * authority (Electronic Invoicing Guidelines v1.1, p45; the obligation
 * stays with it, p11 note 2), so a failed report is asked again: by the
 * hourly peppol:retry-reports, and by an operator from the admin console.
 * A retry moves the report back to pending; the provider's answer moves it
 * on, as the first one did.
 */
final class ReportRetrier
{
    /** Between one ask and the next, so a provider is not asked every minute. */
    public const SPACING_MINUTES = 60;

    /** Past this a failed report is an operator's, not the schedule's. */
    public const WINDOW_DAYS = 30;

    public function __construct(
        private readonly AspGatewayResolver $gateways,
        private readonly DocumentStatus $status,
    ) {}

    /**
     * Failed reports due another ask, oldest first.
     *
     * @return Collection<int, PeppolDocument>
     */
    public function due(int $limit): Collection
    {
        return PeppolDocument::acrossTenants()
            ->where('direction', PeppolDocument::DIRECTION_OUT)
            ->where('reporting_status', DocumentStatus::REPORT_FAILED)
            ->whereNotNull('asp_message_id')
            ->where('reporting_changed_at', '<=', now()->subMinutes(self::SPACING_MINUTES))
            ->where('created_at', '>=', now()->subDays(self::WINDOW_DAYS))
            ->orderBy('reporting_changed_at')
            ->limit($limit)
            ->get();
    }

    /**
     * Ask once.
     *
     * @param  string  $source  the schedule, or an operator (DocumentHistory::SOURCE_*)
     * @return string|null why it was not asked, or null when it was
     */
    public function retry(string $documentId, string $source = DocumentHistory::SOURCE_RETRY): ?string
    {
        $document = PeppolDocument::acrossTenants()->find($documentId);

        if ($document === null || $document->reporting_status !== DocumentStatus::REPORT_FAILED || $document->asp_message_id === null) {
            return 'Only a document whose report to the authority failed can be reported again.';
        }

        try {
            $this->gateways->forDocument($document)->retryReport((string) $document->asp_message_id);
        } catch (NoAspConfiguredException) {
            return 'No service provider is configured.';
        } catch (AspUnavailableException|AspRefusedException $e) {
            Log::warning('Peppol report not asked again', ['document_id' => $documentId, 'error' => $e->getMessage()]);

            // Spaced like an attempt, so the schedule does not ask at once
            // again. Not a move: the report is still failed.
            PeppolDocument::acrossTenants()
                ->whereKey($documentId)
                ->where('reporting_status', DocumentStatus::REPORT_FAILED)
                ->update(['reporting_changed_at' => now()]);

            return $e->getMessage();
        }

        $this->status->transitionReporting($documentId, DocumentStatus::PENDING, ['reporting_errors' => null], DocumentStatus::REPORT_FAILED, $source);

        return null;
    }
}
