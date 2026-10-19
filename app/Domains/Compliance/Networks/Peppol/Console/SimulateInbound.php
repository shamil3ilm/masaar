<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Networks\Peppol\Console;

use App\Domains\Compliance\Networks\Peppol\NetworkServiceProvider;
use App\Domains\Compliance\Networks\Peppol\Simulator\SimulatorCallbacks;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Another supplier's invoice arriving for one of our organizations, played
 * by the service-provider simulator through the signed inbound webhook.
 *
 * The document is a file of your choosing, or the specification's own
 * standard example from PEPPOL_ARTEFACTS_PATH, re-addressed to the endpoint
 * given: its buyer's EndpointID becomes that endpoint, and nothing else
 * changes.
 *
 * Only with PEPPOL_GATEWAY=simulator, which production refuses to boot with.
 */
class SimulateInbound extends Command
{
    protected $signature = 'peppol:simulate-inbound
                            {receiver : The receiving organization\'s endpoint, scheme:identifier}
                            {--file= : A UBL invoice or credit note to deliver, instead of the specification\'s example}';

    protected $description = 'Deliver a document from another supplier to one of our organizations, through the service-provider simulator';

    private const EXAMPLE = '/billing/trn-invoice/example/Standard tax invoice.xml';

    private const CAC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2';

    private const CBC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2';

    public function handle(SimulatorCallbacks $callbacks): int
    {
        if (config('peppol.gateway') !== NetworkServiceProvider::SIMULATOR) {
            $this->error('The simulator is not the gateway here: set PEPPOL_GATEWAY=simulator.');

            return self::FAILURE;
        }

        $receiver = trim((string) $this->argument('receiver'));

        if (! preg_match('/^[^:\s]+:\S+$/', $receiver)) {
            $this->error('The receiver is an endpoint in scheme:identifier form.');

            return self::FAILURE;
        }

        $path = (string) ($this->option('file') ?: rtrim(str_replace('\\', '/', (string) config('peppol.artefacts_path')), '/').self::EXAMPLE);

        if (! is_file($path)) {
            $this->error("No document at {$path}: pass --file, or set PEPPOL_ARTEFACTS_PATH.");

            return self::FAILURE;
        }

        $addressed = $this->addressedTo((string) file_get_contents($path), $receiver);

        if ($addressed === null) {
            $this->error('The document is not a UBL invoice or credit note with a buyer endpoint.');

            return self::FAILURE;
        }

        [$xml, $sender] = $addressed;
        $messageId = 'sim-in-'.Str::uuid();

        $answer = $callbacks->post(SimulatorCallbacks::INBOUND_PATH, [
            'message_id' => $messageId,
            'document' => base64_encode($xml),
            'sender' => $sender,
            'receiver' => $receiver,
        ]);

        return match (true) {
            $answer >= 200 && $answer < 300 => $this->done("Delivered as {$messageId}: it is under Received in the organization's portal."),
            $answer === 404 => $this->failed("No organization here receives at {$receiver}; nothing was stored."),
            $answer === 0 => $this->failed('This platform could not be reached at its callback URL (PEPPOL_SIMULATOR_CALLBACK_URL, else APP_URL).'),
            default => $this->failed("The platform answered HTTP {$answer}."),
        };
    }

    /**
     * The document with its buyer re-addressed, and its seller's endpoint.
     *
     * @return array{string, string}|null
     */
    private function addressedTo(string $xml, string $receiver): ?array
    {
        $dom = new DOMDocument;
        $dom->preserveWhiteSpace = true;

        // LIBXML_NONET: a file handed to this never makes the server fetch.
        if (! @$dom->loadXML($xml, LIBXML_NONET) || $dom->documentElement === null) {
            return null;
        }

        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('cac', self::CAC);
        $xpath->registerNamespace('cbc', self::CBC);

        $buyer = $xpath->query('/*/cac:AccountingCustomerParty/cac:Party/cbc:EndpointID')?->item(0);
        $seller = $xpath->query('/*/cac:AccountingSupplierParty/cac:Party/cbc:EndpointID')?->item(0);

        if (! $buyer instanceof DOMElement || ! $seller instanceof DOMElement) {
            return null;
        }

        [$scheme, $identifier] = explode(':', $receiver, 2);
        $buyer->setAttribute('schemeID', $scheme);
        $buyer->textContent = $identifier;

        return [(string) $dom->saveXML(), $seller->getAttribute('schemeID').':'.trim($seller->textContent)];
    }

    private function done(string $message): int
    {
        $this->info($message);

        return self::SUCCESS;
    }

    private function failed(string $message): int
    {
        $this->error($message);

        return self::FAILURE;
    }
}
