<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Peppol\Services;

use App\Domains\Compliance\Peppol\Gateways\InboundMessage;
use App\Domains\Compliance\Peppol\Models\PeppolDocument;
use App\Domains\Organization\Models\ComplianceProfile;
use DOMDocument;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;

/**
 * Documents other suppliers sent to our organizations.
 *
 * The receiver is matched against the Peppol endpoint the provider issued to
 * each UAE organization, kept on its compliance profile as
 * settings.endpoint_id in "scheme:identifier" form ("0235:1234567890"). A
 * document for an endpoint nobody holds is not stored anywhere: guessing an
 * organization would hand one taxpayer's purchase invoice to another. Two
 * organizations claiming one endpoint is the same risk, refused the same way.
 *
 * The provider retries a callback it thinks failed, so a message already
 * stored is answered with the stored document, never a second copy.
 */
final class InboundDocuments
{
    private const COUNTRY = 'AE';

    /** The prefix some providers put on a participant identifier. */
    private const IDENTIFIER_SCHEME = 'iso6523-actorid-upis::';

    /**
     * @return PeppolDocument|null the stored document, or null when no
     *                             organization of ours receives at that endpoint
     */
    public function receive(InboundMessage $message): ?PeppolDocument
    {
        $existing = $this->stored($message->messageId);

        if ($existing !== null) {
            return $existing;
        }

        $receiver = self::endpoint($message->receiverEndpoint);
        $orgId = $this->organizationAt($receiver);

        if ($orgId === null) {
            Log::warning('Peppol document for an endpoint no organization holds; not stored', [
                'asp_message_id' => $message->messageId,
                'receiver' => $receiver,
            ]);

            return null;
        }

        [$customization, $profile, $type] = $this->describe($message->xml);

        try {
            return PeppolDocument::withoutTenantScope(fn () => PeppolDocument::query()->create([
                'org_id' => $orgId,
                'invoice_id' => null,
                'direction' => PeppolDocument::DIRECTION_IN,
                'country' => self::COUNTRY,
                'customization_id' => $customization,
                'profile_id' => $profile,
                'document_type' => $type,
                'xml' => $message->xml,
                'asp_message_id' => $message->messageId,
                'status' => DocumentStatus::RECEIVED,
                'sender_endpoint' => self::endpoint($message->senderEndpoint),
                'receiver_endpoint' => $receiver,
            ]));
        } catch (UniqueConstraintViolationException) {
            // The same callback, twice at once: the other one stored it.
            return $this->stored($message->messageId);
        }
    }

    /**
     * "iso6523-actorid-upis::0235:1234567890" and " 0235:1234567890 " are
     * the same participant.
     */
    public static function endpoint(string $value): string
    {
        $value = trim($value);

        if (str_starts_with(strtolower($value), self::IDENTIFIER_SCHEME)) {
            $value = substr($value, strlen(self::IDENTIFIER_SCHEME));
        }

        return $value;
    }

    private function stored(string $messageId): ?PeppolDocument
    {
        return PeppolDocument::withoutTenantScope(fn () => PeppolDocument::query()
            ->where('asp_message_id', $messageId)
            ->first());
    }

    private function organizationAt(string $endpoint): ?string
    {
        if ($endpoint === '') {
            return null;
        }

        $holders = ComplianceProfile::withoutTenantScope(fn () => ComplianceProfile::query()
            ->where('jurisdiction', self::COUNTRY)
            ->where('settings->endpoint_id', $endpoint)
            ->where('status', '!=', ComplianceProfile::STATUS_REVOKED)
            ->pluck('org_id')
            ->unique()
            ->values());

        if ($holders->count() > 1) {
            Log::critical('Two organizations hold one Peppol endpoint; inbound documents for it are not stored', [
                'receiver' => $endpoint,
                'org_ids' => $holders->all(),
            ]);

            return null;
        }

        return $holders->first();
    }

    /**
     * CustomizationID, ProfileID and the root element (Invoice, CreditNote)
     * of a received document, blank where the XML does not say.
     *
     * Stored whatever it says: the buyer's provider has validated it already,
     * and what the taxpayer received must be kept as it arrived.
     *
     * @return array{0: string, 1: string, 2: string}
     */
    private function describe(string $xml): array
    {
        $dom = new DOMDocument;

        // LIBXML_NONET: a received document never makes this server fetch.
        if (! @$dom->loadXML($xml, LIBXML_NONET) || $dom->documentElement === null) {
            return ['', '', ''];
        }

        $root = $dom->documentElement;
        $child = function (string $name) use ($root): string {
            foreach ($root->childNodes as $node) {
                if ($node->localName === $name) {
                    return trim((string) $node->textContent);
                }
            }

            return '';
        };

        return [
            mb_substr($child('CustomizationID'), 0, 255),
            mb_substr($child('ProfileID'), 0, 255),
            mb_substr((string) $root->localName, 0, 30),
        ];
    }
}
