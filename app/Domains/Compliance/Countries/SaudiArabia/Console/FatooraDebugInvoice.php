<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\SaudiArabia\Console;

use App\Domains\Compliance\Countries\SaudiArabia\Signing\TlvEncoder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Inspect an invoice, its signing state, cryptographic hash, and submission history.
 *
 * Provides a comprehensive, colored diagnostic view for developers and operators
 * troubleshooting ZATCA clearance, reporting rejections, or QR code payloads.
 */
class FatooraDebugInvoice extends Command
{
    protected $signature = 'fatoora:debug-invoice
                            {invoice : The invoice UUID or invoice number}
                            {--xml : Dump the formatted signed or cleared XML}
                            {--qr : Decode and dump the 9-tag TLV QR code payload}
                            {--raw : Dump raw ZATCA response payload}';

    protected $description = 'Inspect an invoice, its signing state, hash chain, and detailed submission history';

    public function __construct(
        private readonly TlvEncoder $tlv,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $identifier = (string) $this->argument('invoice');

        $invoice = DB::table('invoices')
            ->where('id', $identifier)
            ->orWhere('invoice_number', $identifier)
            ->first();

        if ($invoice === null) {
            $this->error("Invoice '{$identifier}' not found.");

            return self::FAILURE;
        }

        $org = DB::table('organizations')->where('id', $invoice->org_id)->first();

        $pih = DB::table('hash_chain_history')->where('invoice_id', $invoice->id)->value('previous_hash');
        if ($pih === null && ! empty($invoice->signed_xml)) {
            try {
                $dom = new \DOMDocument();
                $prevErrors = libxml_use_internal_errors(true);
                if ($dom->loadXML((string) $invoice->signed_xml)) {
                    $xpath = new \DOMXPath($dom);
                    $xpath->registerNamespace('cac', 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2');
                    $xpath->registerNamespace('cbc', 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');
                    $extracted = trim((string) $xpath->evaluate('string(//cac:AdditionalDocumentReference[cbc:ID="PIH"]//cbc:EmbeddedDocumentBinaryObject)'));
                    if ($extracted !== '') {
                        $pih = $extracted;
                    }
                }
                libxml_clear_errors();
                libxml_use_internal_errors($prevErrors);
            } catch (\Throwable) {
                // Ignore XML parsing errors for diagnostics
            }
        }

        $this->info("=== Invoice Diagnostics: {$invoice->invoice_number} ({$invoice->id}) ===");
        $this->table(
            ['Property', 'Value'],
            [
                ['Organization', ($org?->name ?? 'Unknown').' ('.($org?->vat_number ?? 'No VAT').')'],
                ['Document Type', strtoupper((string) $invoice->type).' / '.strtoupper((string) $invoice->document_type)],
                ['Status', strtoupper((string) $invoice->status)],
                ['Issue Date / Time', "{$invoice->issue_date} ".($invoice->issued_at ?? 'N/A')],
                ['Currency & Total', "{$invoice->currency} {$invoice->total} (Tax: {$invoice->tax_amount})"],
                ['ICV (Sequence Counter)', $invoice->icv !== null ? (string) $invoice->icv : '<not numbered>'],
                ['Invoice Hash (SHA-256)', $invoice->hash ?? '<not hashed>'],
                ['Previous Hash (PIH)', $pih ?? '<none / genesis>'],
                ['Signing Certificate ID', $invoice->cert_id ?? '<none>'],
                ['Device / EGS Till', $invoice->device_id ?? '<default>'],
            ]
        );

        $submissions = DB::table('invoice_submissions')
            ->where('invoice_id', $invoice->id)
            ->orderByDesc('created_at')
            ->get();

        if ($submissions->isNotEmpty()) {
            $this->line('');
            $this->info("--- Submission History (".count($submissions)." attempt(s)) ---");
            $rows = [];
            foreach ($submissions as $sub) {
                $status = $sub->clearance_status ?? $sub->reporting_status ?? $sub->state ?? 'UNKNOWN';
                $errors = ! empty($sub->zatca_errors) ? (string) $sub->zatca_errors : ($sub->last_error ?? 'None');
                $rows[] = [
                    $sub->id,
                    $sub->created_at ?? 'N/A',
                    $sub->submission_type ?? 'N/A',
                    $status,
                    $sub->retry_count ?? 0,
                    $errors,
                ];
            }
            $this->table(['Submission ID', 'Submitted At', 'Type', 'Status', 'Retries', 'Errors / Notes'], $rows);
        } else {
            $this->warn('No submission records found in invoice_submissions table.');
        }

        if ($this->option('qr') && filled($invoice->qr_code)) {
            $this->line('');
            $this->info('--- TLV QR Code Decoded (Tags 1 to 9) ---');
            try {
                $tags = $this->tlv->decode((string) $invoice->qr_code);
                $tagNames = [
                    1 => 'Seller Name',
                    2 => 'VAT Number',
                    3 => 'Timestamp',
                    4 => 'Invoice Total',
                    5 => 'VAT Total',
                    6 => 'Invoice Hash (SHA-256)',
                    7 => 'ECDSA Signature',
                    8 => 'ECDSA Public Key',
                    9 => 'Certificate Stamp',
                ];

                $qrRows = [];
                for ($t = 1; $t <= 9; $t++) {
                    if (isset($tags[$t])) {
                        $val = $tags[$t];
                        $len = strlen($val);
                        $display = in_array($t, [6, 7, 8, 9], true)
                            ? bin2hex(substr($val, 0, 16)).'... ('.$len.' bytes)'
                            : $val;
                        $qrRows[] = [$t, $tagNames[$t] ?? 'Custom', $len.' bytes', $display];
                    } else {
                        $qrRows[] = [$t, $tagNames[$t] ?? 'Custom', '0 bytes', '<absent>'];
                    }
                }
                $this->table(['Tag', 'Name', 'Length', 'Value Preview'], $qrRows);
            } catch (\Throwable $e) {
                $this->error('Failed to decode QR code TLV: '.$e->getMessage());
            }
        }

        if ($this->option('xml')) {
            $this->line('');
            $xml = $invoice->cleared_xml ?? $invoice->signed_xml;
            if (filled($xml)) {
                $this->info('--- Formatted XML Payload ---');
                $dom = new \DOMDocument;
                $dom->preserveWhiteSpace = false;
                $dom->formatOutput = true;
                $prevErrors = libxml_use_internal_errors(true);
                $loaded = $dom->loadXML((string) $xml);
                libxml_clear_errors();
                libxml_use_internal_errors($prevErrors);
                if ($loaded) {
                    $this->line((string) $dom->saveXML());
                } else {
                    $this->line((string) $xml);
                }
            } else {
                $this->warn('No signed or cleared XML found on this invoice.');
            }
        }

        if ($this->option('raw') && ! empty($invoice->zatca_response)) {
            $this->line('');
            $this->info('--- Raw ZATCA Response JSON ---');
            $raw = is_string($invoice->zatca_response) ? json_decode($invoice->zatca_response, true) : $invoice->zatca_response;
            $this->line(json_encode($raw, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }

        return self::SUCCESS;
    }
}
