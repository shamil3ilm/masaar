<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\SaudiArabia\Console\Concerns;

use App\Domains\Compliance\Countries\SaudiArabia\DTOs\InvoiceXmlData;
use App\Domains\Compliance\Countries\SaudiArabia\Filing\FatooraClient;
use Illuminate\Console\Command;

/**
 * fatoora:onboard --step=submit: the sample documents cleared and reported
 * with the production certificate.
 *
 * Its own request rather than FatooraClient::clearInvoice(): clearance here
 * sends Clearance-Status: 1, which asks the authority to stamp the document,
 * and the client's clearance call does not send it.
 *
 * Needs StoresCsids and StampsSampleQr alongside it.
 */
trait SubmitsWithPcsid
{
    /**
     * Clear and report real documents with the production certificate.
     *
     * The step that was missing, and the gap it left is the largest in this
     * platform's verification. Onboarding ends at a production CSID;
     * everything after that - whether the authority accepts a signed invoice,
     * what it returns, whether a stamped copy comes back - had been
     * established only against the SDK offline, or against the compliance
     * endpoint, which is a different endpoint with different rules.
     *
     * It needs no taxpayer. The compliance check already obtains a PCSID from
     * the developer portal, and clearance and reporting are served there too.
     * This was described as needing a real taxpayer because nobody had tried.
     *
     * The same six documents, sent somewhere else. They are already a valid
     * chain - ICV one to six, each PIH the previous hash - so they are reused
     * rather than invented, signed this time with the production certificate
     * rather than the compliance one. Standard documents go to clearance,
     * because B2B must be cleared before it is issued; simplified go to
     * reporting, where B2C is sent within twenty-four hours. Which endpoint a
     * document belongs to is its subtype's answer, not a setting.
     */
    private function submitWithProductionCsid(): int
    {
        $this->info('Submitting documents with the production certificate');
        $this->newLine();

        $pcsid = $this->loadPcsidCredentials();

        if ($pcsid === null) {
            $this->error('No production credentials. Run --step=pcsid first.');

            return Command::FAILURE;
        }

        if (empty($pcsid['certificate']) || empty($pcsid['privateKey'])) {
            $this->error('The production certificate or its key is missing, so nothing can be signed.');

            return Command::FAILURE;
        }

        $this->line("Endpoint: {$this->baseUrl}");
        $this->newLine();

        try {
            $documents = $this->sampleDocuments(
                'LIVE-'.date('YmdHis'),
                fn (string $xml, InvoiceXmlData $data): string => $this->injectQrCode(
                    $this->signer->sign($xml, $pcsid['privateKey'], $pcsid['certificate']),
                    $data,
                    $pcsid['certificate']
                ),
            );
        } catch (\Exception $e) {
            $this->error('Could not build the documents: '.$e->getMessage());

            return Command::FAILURE;
        }

        $results = [];
        $allPassed = true;

        foreach ($documents as $key => $document) {
            $standard = $document['data']->invoiceSubtype === '01';
            $route = $standard ? 'clearance' : 'reporting';
            $name = ucwords(str_replace('_', ' ', $key));

            $this->line("Submitting {$name} for {$route}...");

            $result = $this->submitForOutcome($document, $pcsid, $standard);

            if (! $result['success']) {
                $allPassed = false;
            }

            $results[] = [
                'Document' => $name,
                'Route' => $route,
                'Status' => $result['success'] ? 'ACCEPTED' : 'REFUSED',
                'Outcome' => $result['message'],
            ];

            // The authority rate limits, and a tight loop is how a device
            // finds that out the expensive way.
            usleep(500000);
        }

        $this->newLine();
        $this->table(['Document', 'Route', 'Status', 'Outcome'], $results);
        $this->newLine();

        if (! $allPassed) {
            $this->error('A document was refused. The outcome column carries what the authority said.');

            return Command::FAILURE;
        }

        $this->info('All 6 documents were accepted by the production endpoints!');

        return Command::SUCCESS;
    }

    /**
     * One document, to the endpoint its subtype belongs to.
     *
     * @param  array{xml: string, hash: string, data: InvoiceXmlData}  $document
     * @param  array<string, string|null>  $pcsid
     * @return array{success: bool, message: string}
     */
    private function submitForOutcome(array $document, array $pcsid, bool $standard): array
    {
        $path = $standard ? FatooraClient::CLEARANCE : FatooraClient::REPORTING;

        try {
            $request = $this->zatca()
                ->withBasicAuth((string) $pcsid['token'], (string) $pcsid['secret']);

            // Clearance is the only one that takes this, and it is what asks
            // the authority to stamp the document rather than merely accept a
            // report of it.
            if ($standard) {
                $request = $request->withHeaders(['Clearance-Status' => '1']);
            }

            $response = $request->post($this->baseUrl.$path, [
                'invoiceHash' => $document['hash'],
                'uuid' => $document['data']->uuid,
                'invoice' => base64_encode($document['xml']),
            ]);

            $body = (array) $response->json();

            if ($response->successful()) {
                $status = (string) ($body['clearanceStatus'] ?? $body['reportingStatus'] ?? 'ACCEPTED');
                $cleared = ! empty($body['clearedInvoice']);

                return [
                    'success' => true,
                    'message' => $status.($cleared ? ' (stamped copy returned)' : ''),
                ];
            }

            // The reasons, not a status code: a refusal names the rule, and
            // that is the only part worth reading.
            $reasons = array_merge(
                array_column((array) ($body['errorMessages'] ?? []), 'message'),
                array_column((array) ($body['validationResults']['errorMessages'] ?? []), 'message'),
            );

            return [
                'success' => false,
                'message' => $reasons === []
                    ? 'HTTP '.$response->status()
                    : implode('; ', array_slice($reasons, 0, 3)),
            ];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => 'GATEWAY: '.$e->getMessage()];
        }
    }
}
