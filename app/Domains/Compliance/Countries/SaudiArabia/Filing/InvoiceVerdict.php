<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\SaudiArabia\Filing;

use App\Domains\Compliance\Countries\SaudiArabia\DTOs\FatooraResponse;
use App\Domains\Compliance\Countries\SaudiArabia\Rules\FatooraConfig;
use App\Domains\Invoice\Enums\InvoiceStatus;
use App\Domains\Invoice\Models\Invoice;
use App\Support\Xml;
use App\Support\XmlException;
use DOMDocument;
use DOMXPath;
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
    public function __construct(
        private readonly FilingPause $pause,
    ) {}

    /**
     * Record the answer in one transaction, so the invoice and its branch's
     * count cannot disagree.
     */
    public function record(Invoice $invoice, FatooraResponse $response): void
    {
        // Every filing path ends here, so this is where an answer that
        // judged nothing - a 401, a 413, a 5xx, a timeout, our own exception
        // - is kept from reading as Rejected (Detailed Guidelines, responses
        // and required actions). The invoice stays issued, to be sent again;
        // the submission or queue item keeps the reason.
        if (! $response->isVerdict()) {
            Log::warning('ZATCA did not judge the invoice; it stays issued', [
                'invoice_id' => $invoice->id,
                'error_code' => $response->errorCode?->value,
                'errors' => $response->errorMessages,
            ]);

            // Credentials refused: the device stops filing until renewed.
            $this->pause->afterAnswer($invoice, $response);

            return;
        }

        DB::transaction(function () use ($invoice, $response): void {
            // Read under a lock, so two answers for one invoice are decided
            // one after the other against the status the first left.
            $current = Invoice::acrossTenants()
                ->whereKey($invoice->getKey())->lockForUpdate()->value('status');

            if ($current === InvoiceStatus::Draft) {
                throw new \LogicException("Invoice {$invoice->id} is a draft; nothing was filed for ZATCA to answer.");
            }

            // Acceptance is final. A filing that timed out after ZATCA had
            // accepted it is retried, the retry is told the invoice was
            // already submitted, and that error was written over the
            // acceptance - cleared copy and all.
            if ($current === InvoiceStatus::Accepted) {
                Log::warning('ZATCA answered an invoice it had already accepted; the acceptance stands.', [
                    'invoice_id' => $invoice->id,
                    'success' => $response->success,
                    'errors' => $response->errorMessages,
                ]);

                return;
            }

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
                // Rule code, category and message each, so a rejected
                // invoice says which rule failed (IssueNotices::rejections()).
                'warnings' => $response->warningDetails(),
                'errors' => $response->errorDetails(),
            ],
        ];

        // ZATCA stamps the invoice it clears and returns it. That stamped copy
        // is the legal invoice; the one submitted is only what was asked for.
        // Only clearance returns a document, so a simplified invoice has none.
        if ($cleared = $this->clearedXml($response)) {
            $changes['cleared_xml'] = $cleared;

            // KSA-14 on a standard invoice is "applied by ZATCA": the QR the
            // buyer scans is the one in the stamped copy, not the one built
            // before submission. Without one, the issued QR stands.
            if ($qr = $this->clearedQr($cleared)) {
                $changes['qr_code'] = $qr;
            }
        }

        return $changes;
    }

    /**
     * The QR the authority put in the cleared document, if it put one there.
     *
     * Null for anything that does not parse, so a document kept verbatim
     * because it did not decode leaves the QR alone rather than failing the
     * verdict.
     */
    private function clearedQr(string $xml): ?string
    {
        try {
            $dom = Xml::load(new DOMDocument, $xml);
        } catch (XmlException) {
            return null;
        }

        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('cac', FatooraConfig::CAC_NS);
        $xpath->registerNamespace('cbc', FatooraConfig::CBC_NS);

        $qr = trim((string) $xpath->evaluate(
            'string(/*/cac:AdditionalDocumentReference[normalize-space(cbc:ID) = "QR"]/cac:Attachment/cbc:EmbeddedDocumentBinaryObject)'
        ));

        return $qr === '' ? null : $qr;
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
