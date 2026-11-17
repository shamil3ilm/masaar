<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Networks\Peppol\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Inspect a Peppol / UAE PINT AE document, transmission status, and event audit history.
 *
 * Provides a comprehensive diagnostic view for developers and operators
 * troubleshooting UAE FTA Peppol transmissions, ASP rejections, or XML payloads.
 */
class PeppolDebugDocument extends Command
{
    protected $signature = 'peppol:debug-document
                            {document : The invoice UUID, invoice number, Peppol document UUID, or ASP message ID}
                            {--xml : Dump the formatted PINT AE UBL 2.1 XML}
                            {--events : Dump the complete event lifecycle and callback history}
                            {--raw : Dump raw error payloads and provider metadata}';

    protected $description = 'Inspect a Peppol / UAE document, its transmission status, and event history';

    public function handle(): int
    {
        $identifier = (string) $this->argument('document');

        $doc = DB::table('peppol_documents')
            ->where('id', $identifier)
            ->orWhere('document_uuid', $identifier)
            ->orWhere('invoice_id', $identifier)
            ->orWhere('asp_message_id', $identifier)
            ->first();

        if ($doc === null) {
            $invoice = DB::table('invoices')
                ->where('invoice_number', $identifier)
                ->first();

            if ($invoice !== null) {
                $doc = DB::table('peppol_documents')
                    ->where('invoice_id', $invoice->id)
                    ->first();
            }
        }

        if ($doc === null) {
            $this->error("Peppol document '{$identifier}' not found.");

            return self::FAILURE;
        }

        $org = DB::table('organizations')->where('id', $doc->org_id)->first();
        $invoice = $doc->invoice_id ? DB::table('invoices')->where('id', $doc->invoice_id)->first() : null;

        $direction = strtoupper((string) $doc->direction) === 'OUT' ? 'OUTBOUND (SENT)' : 'INBOUND (RECEIVED)';

        $this->info("=== Peppol / UAE Document Diagnostics: {$doc->id} ===");
        $this->table(
            ['Property', 'Value'],
            [
                ['Organization', ($org?->name ?? 'Unknown').' ('.($org?->vat_number ?? 'No TRN').')'],
                ['Invoice Reference', $invoice ? "{$invoice->invoice_number} ({$invoice->id})" : '<none / inbound>'],
                ['Direction', $direction],
                ['Country & Document Type', "{$doc->country} / {$doc->document_type}"],
                ['Customization ID', $doc->customization_id ?? '<default>'],
                ['Profile ID', $doc->profile_id ?? '<default>'],
                ['Transmission Status', strtoupper((string) ($doc->status ?? 'UNKNOWN'))],
                ['Sender Endpoint', $doc->sender_endpoint ?? '<not set>'],
                ['Receiver Endpoint', $doc->receiver_endpoint ?? '<not set>'],
                ['ASP Message ID', $doc->asp_message_id ?? '<none>'],
                ['Provider Reference', $doc->provider_reference ?? '<none>'],
                ['Authority Reporting Status', strtoupper((string) ($doc->reporting_status ?? 'NOT_REPORTED'))],
                ['Authority Reference', $doc->authority_reference ?? '<none>'],
                ['MLS Response Code', $doc->mls_code ?? '<none>'],
                ['Transmission Attempts', (string) ($doc->attempts ?? 0)],
                ['Last Transmission Error', $doc->last_error ?? 'None'],
                ['Created At / Updated At', "{$doc->created_at} / {$doc->updated_at}"],
            ]
        );

        $events = DB::table('peppol_document_events')
            ->where('document_id', $doc->id)
            ->orderBy('occurred_at')
            ->get();

        if ($events->isNotEmpty() && $this->option('events')) {
            $this->line('');
            $this->info("--- Event Lifecycle History (".count($events)." event(s)) ---");
            $rows = [];
            foreach ($events as $event) {
                $errors = ! empty($event->errors) ? (string) $event->errors : 'None';
                $rows[] = [
                    $event->id,
                    $event->occurred_at ?? 'N/A',
                    $event->source ?? 'N/A',
                    $event->track ?? 'N/A',
                    $event->status ?? 'N/A',
                    $event->reporting_status ?? 'N/A',
                    $event->mls_code ?? '<none>',
                    $errors,
                ];
            }
            $this->table(['Event ID', 'Occurred At', 'Source', 'Track', 'Status', 'Reporting', 'MLS Code', 'Errors'], $rows);
        } elseif ($events->isNotEmpty() && ! $this->option('events')) {
            $this->line('');
            $this->comment('Tip: Pass --events to see all '.count($events).' transmission lifecycle events.');
        } else {
            $this->line('');
            $this->info('--- Event History: None recorded ---');
        }

        if ($this->option('xml')) {
            $this->line('');
            $xml = $doc->xml;
            if (filled($xml)) {
                $this->info('--- Formatted PINT AE XML Payload ---');
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
                $this->warn('No XML payload stored for this document.');
            }
        }

        if ($this->option('raw')) {
            $this->line('');
            $this->info('--- Raw Errors & Reporting JSON ---');
            $errors = is_string($doc->errors) ? json_decode($doc->errors, true) : $doc->errors;
            $reportingErrors = is_string($doc->reporting_errors) ? json_decode($doc->reporting_errors, true) : $doc->reporting_errors;
            $this->line(json_encode([
                'errors' => $errors,
                'reporting_errors' => $reportingErrors,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }

        return self::SUCCESS;
    }
}
