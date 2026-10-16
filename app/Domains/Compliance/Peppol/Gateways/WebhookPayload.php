<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Peppol\Gateways;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * The generic callback bodies, until a provider's own are known.
 *
 *   status:  {"message_id", "status": sent|delivered|rejected|reported,
 *             "mls_code": AB|AP|RE, "errors": [string]}
 *   inbound: {"message_id", "document": base64 XML, "sender", "receiver"}
 *
 * A status may come as an MLS code alone. A body that does not fit is
 * refused with 422 rather than half-read, so the provider sees it and retries.
 */
final class WebhookPayload
{
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

        return new AspStatus($messageId, $status, $mls, $errors);
    }

    /**
     * @throws ValidationException
     */
    public static function inbound(Request $request): InboundMessage
    {
        $data = Validator::make($request->json()->all(), [
            'message_id' => ['required', 'string', 'max:255'],
            'document' => ['required', 'string'],
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
