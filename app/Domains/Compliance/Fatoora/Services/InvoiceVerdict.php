<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Fatoora\Services;

use App\Domains\Compliance\Fatoora\DTOs\FatooraResponse;
use App\Domains\Invoice\Enums\InvoiceStatus;
use App\Domains\Invoice\Models\Invoice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * What ZATCA's answer changes on the invoice itself.
 *
 * The invoice's status and the authority's response, the cleared document when
 * there is one, and the issuing branch's invoice count. Submitter and the
 * offline queue both receive answers and both record them here.
 */
class InvoiceVerdict
{
    /**
     * Record the answer in one transaction, so the invoice and its branch's
     * count cannot disagree.
     */
    public function record(Invoice $invoice, FatooraResponse $response): void
    {
        DB::transaction(function () use ($invoice, $response): void {
            $invoice->update($this->changes($response));

            if ($response->success && $invoice->branch_id && $invoice->branch) {
                $invoice->branch->incrementInvoiceCount();
            }
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function changes(FatooraResponse $response): array
    {
        $changes = [
            'status' => $response->success ? InvoiceStatus::Accepted : InvoiceStatus::Rejected,
            'zatca_response' => [
                'clearance_status' => $response->clearanceStatus,
                'reporting_status' => $response->reportingStatus,
                'validation_status' => $response->validationStatus,
                'warnings' => $response->warningMessages,
                'errors' => $response->errorMessages,
            ],
        ];

        // ZATCA stamps the invoice it clears and returns it. That stamped copy
        // is the legal invoice; the one submitted is only what was asked for.
        // Only clearance returns a document, so a simplified invoice has none.
        if ($cleared = $this->clearedXml($response)) {
            $changes['cleared_xml'] = $cleared;
        }

        return $changes;
    }

    /**
     * The cleared document from a response, as XML.
     *
     * ZATCA returns it base64-encoded. Anything that does not decode to a
     * document is kept verbatim rather than discarded — losing the authority's
     * copy because it arrived in an unexpected shape is the worse failure, and
     * it is visible either way.
     */
    private function clearedXml(FatooraResponse $response): ?string
    {
        if (empty($response->clearedInvoice)) {
            return null;
        }

        $decoded = base64_decode($response->clearedInvoice, true);

        if ($decoded === false || ! str_contains($decoded, '<')) {
            Log::warning('Cleared invoice did not decode as XML; keeping it as sent.', [
                'length' => strlen($response->clearedInvoice),
            ]);

            return $response->clearedInvoice;
        }

        return $decoded;
    }
}
