<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Networks\Peppol\Gateways;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * The generic callback bodies, until a provider's own are known.
 *
 *   status:  {"message_id", "status": sent|delivered|rejected|reported|report_failed,
 *             "mls_code": AB|AP|RE, "errors": [string],
 *             "authority_reference": the authority's reference for a report}
 *   inbound: {"message_id", "document": base64 XML, "sender", "receiver"}
 *
 * A status may come as an MLS code alone. A body that does not fit is
 * refused with 422 rather than half-read, so the provider sees it and retries.
 */
final class WebhookPayload
{
    private const MAX_DOCUMENT_CHARS = 18_000_000;

    /**
     * @throws ValidationException
     */
    public static function status(Request $request): AspStatus
    {
        $data = Validator::make($request->json()->all(), [
            'message_id' => ['required', 'string', 'max:255'],
            'status' => ['nullable', 'string', 'max:30'],
            'mls_code' => ['nullable', 'string', 'max:20'],
            'errors' => ['nullable', 'array', 'max:200'],
            'errors.*' => ['string', 'max:2000'],
            'authority_reference' => ['nullable', 'string', 'max:255'],
        ])->validate();

        return self::statusFrom($data['message_id'], $data);
    }

    /**
     * A status body, from a callback or a status lookup. Lenient: what does
     * not fit is dropped, and an unknown status means nothing new.
     *
     * @param  array<string, mixed>  $data
     */
    public static function statusFrom(string $messageId, array $data): AspStatus
    {
        $mls = is_string($data['mls_code'] ?? null) ? strtoupper($data['mls_code']) : null;

        $status = in_array($data['status'] ?? null, AspStatus::ALL, true)
            ? $data['status']
            : AspStatus::fromMlsCode($mls);

        $errors = array_values(array_filter((array) ($data['errors'] ?? []), 'is_string'));

        $reference = is_string($data['authority_reference'] ?? null) && $data['authority_reference'] !== ''
            ? mb_substr($data['authority_reference'], 0, 255)
            : null;

        return new AspStatus($messageId, $status, $mls, $errors, $reference);
    }

    /**
     * A status lookup's answer, one status per track: the delivery status
     * under status/mls_code/errors, the report under reporting_status,
     * authority_reference and reporting_errors. A track the provider has
     * nothing on is left out.
     *
     * @param  array<string, mixed>  $data
     * @return list<AspStatus>
     */
    public static function lookupFrom(string $messageId, array $data): array
    {
        $delivery = self::statusFrom($messageId, [...$data, 'authority_reference' => null]);
        $reporting = self::statusFrom($messageId, [
            'status' => $data['reporting_status'] ?? null,
            'errors' => $data['reporting_errors'] ?? [],
            'authority_reference' => $data['authority_reference'] ?? null,
        ]);

        return array_values(array_filter(
            [$delivery->aboutReporting() ? null : $delivery, $reporting->aboutReporting() ? $reporting : null],
            fn (?AspStatus $status): bool => $status !== null && $status->status !== null,
        ));
    }

    /**
     * @throws ValidationException
     */
    public static function inbound(Request $request): InboundMessage
    {
        $data = Validator::make($request->json()->all(), [
            'message_id' => ['required', 'string', 'max:255'],
            // Base64 of the XML: about 13 MB of document, far above any
            // invoice, and a bound on what one callback can make this store.
            'document' => ['required', 'string', 'max:'.self::MAX_DOCUMENT_CHARS],
            'sender' => ['required', 'string', 'max:255'],
            'receiver' => ['required', 'string', 'max:255'],
        ])->validate();

        $xml = base64_decode($data['document'], true);

        if ($xml === false || trim($xml) === '') {
            throw ValidationException::withMessages(['document' => 'The document is not base64 encoded XML.']);
        }

        return new InboundMessage(
            messageId: $data['message_id'],
            xml: $xml,
            senderEndpoint: $data['sender'],
            receiverEndpoint: $data['receiver'],
        );
    }
}
