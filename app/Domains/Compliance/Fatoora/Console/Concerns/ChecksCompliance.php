<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Fatoora\Console\Concerns;

use App\Domains\Compliance\Fatoora\Client\FatooraClient;
use App\Domains\Compliance\Fatoora\Console\SdkValidator;
use App\Domains\Compliance\Fatoora\DTOs\InvoiceXmlData;
use Illuminate\Console\Command;

/**
 * fatoora:onboard --step=compliance: the six sample documents, sent to the
 * compliance endpoint with the compliance CSID, or checked locally.
 *
 * Its own request rather than FatooraClient::checkCompliance(): this reports
 * the HTTP status and the authority's first error as it arrived, and counts
 * a document passed on its validation status, where the client's response
 * keeps neither the status nor the error's shape.
 *
 * Needs StoresCsids, OnboardsLocally and StampsSampleQr alongside it.
 */
trait ChecksCompliance
{
    private function runComplianceCheck(): int
    {
        $ccsid = $this->loadCcsidCredentials();
        if (! $ccsid) {
            $this->error('CCSID credentials not found. Run --step=ccsid first.');

            return Command::FAILURE;
        }

        // Check if we have signing credentials
        $canSign = ! empty($ccsid['certificate']) && ! empty($ccsid['privateKey']);
        if (! $canSign) {
            $this->warn('Certificate or private key not found. Invoices will be submitted unsigned.');
            $this->line('For proper compliance, provide --key option when running --step=ccsid');
            $this->newLine();
        } else {
            $this->info('Signing credentials loaded successfully.');
        }

        $isLocalMode = $this->environment === 'local';
        $sdk = new SdkValidator;
        if ($isLocalMode) {
            $this->info('Running LOCAL 6-Invoice Compliance Check...');

            if ($sdk->available()) {
                $this->line('Each document is signed with the local certificate and checked by the ZATCA SDK.');
                $this->line('Its certificate, QR, signature and PIH checks need a ZATCA-issued certificate,');
                $this->line('so the schema (XSD), EN16931 and KSA rules decide the result.');
            } else {
                $this->warn('NOTE: Without the ZATCA SDK, local mode checks XML structure only.');
                $this->line('      Set ZATCA_SDK_PATH to validate against ZATCA\'s schema and rules.');
            }
        } else {
            $this->info("Running 6-Invoice Compliance Check on {$this->environment}...");
        }
        $this->info('Note: ICV is sequential across ALL types (1-6), PIH chains all documents');
        $this->newLine();

        $results = [];
        $allPassed = true;

        // Sign whenever there is a certificate, locally too: an unsigned
        // document fails the SDK's schema stage on its empty signature
        // extension, which says nothing about the invoice. The chain then
        // follows the signed, QR-stamped bytes that are submitted.
        $finalize = $canSign
            ? fn (string $xml, InvoiceXmlData $data): string => $this->injectQrCode(
                $this->signer->sign($xml, $ccsid['privateKey'], $ccsid['certificate']),
                $data,
                $ccsid['certificate']
            )
            : null;

        try {
            $documents = $this->sampleDocuments('COMP-'.date('Ymd'), $finalize);
        } catch (\Exception $e) {
            $this->error('Could not build the compliance documents: '.$e->getMessage());

            return Command::FAILURE;
        }

        $num = 0;

        foreach ($documents as $key => $document) {
            $name = ucwords(str_replace('_', ' ', $key));
            $this->line('['.(++$num)."/6] Submitting {$name}...");

            try {
                $result = $isLocalMode
                    ? $this->validateInvoiceLocally($document['xml'], $document['data']->uuid, $sdk)
                    : $this->submitComplianceInvoice($document['xml'], $document['hash'], $document['data']->uuid, $ccsid);

                if (! $result['success']) {
                    $allPassed = false;
                }

                $results[] = [
                    'Invoice' => $name,
                    'Status' => $result['success'] ? '✓ PASSED' : '✗ FAILED',
                    'Message' => $result['message'] ?? '',
                ];
            } catch (\Exception $e) {
                $allPassed = false;
                $results[] = [
                    'Invoice' => $name,
                    'Status' => '✗ ERROR',
                    'Message' => $e->getMessage(),
                ];
            }

            // Small delay between requests
            usleep(500000);
        }

        $this->newLine();
        $this->table(['Invoice', 'Status', 'Message'], $results);
        $this->newLine();

        if ($allPassed) {
            $this->info('All 6 invoices passed compliance check!');
            $this->newLine();
            $this->info('NEXT: Request Production CSID:');
            $this->line("  php artisan fatoora:onboard --step=pcsid --target={$this->environment}");

            return Command::SUCCESS;
        }

        $this->error('Some invoices failed compliance check. Review errors and retry.');

        return Command::FAILURE;
    }

    private function submitComplianceInvoice(string $xml, string $hash, string $uuid, array $ccsid): array
    {
        try {
            $response = $this->zatca()
                ->withBasicAuth($ccsid['token'], $ccsid['secret'])
                ->post($this->baseUrl.FatooraClient::COMPLIANCE_INVOICES, [
                    'invoiceHash' => $hash, // Already base64-encoded from InvoiceHasher
                    'uuid' => $uuid,
                    'invoice' => base64_encode($xml),
                ]);

            if ($response->successful()) {
                $data = $response->json();
                $status = $data['validationResults']['status'] ?? $data['clearanceStatus'] ?? 'UNKNOWN';

                return [
                    'success' => in_array($status, ['PASS', 'PASSED', 'CLEARED', 'REPORTED']),
                    'message' => $status,
                ];
            }

            // ZATCA answers a refused document in more than one shape, and
            // reporting only the two we recognised turned every other refusal
            // into a bare "HTTP 400" - which says the request was wrong and
            // nothing about what was wrong with it.
            // A 5xx is not a refusal: the gateway failed to answer and the
            // document was never judged. Calling it one sends the reader after
            // a payload bug that is not there.
            $errorMsg = ($response->serverError() ? 'GATEWAY ' : '').'HTTP '.$response->status();
            $errors = $response->json('errors')
                ?? $response->json('validationResults.errorMessages')
                ?? $response->json('validationResults.errors')
                ?? [];

            if (! empty($errors)) {
                $first = $errors[0] ?? $errors;
                $errorMsg .= ': '.(is_array($first) ? ($first['message'] ?? json_encode($first)) : (string) $first);
            } else {
                $body = trim((string) $response->body());
                $errorMsg .= ': '.($body === '' ? '(empty response)' : mb_substr($body, 0, 300));
            }

            return ['success' => false, 'message' => $errorMsg];

        } catch (\Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
}
