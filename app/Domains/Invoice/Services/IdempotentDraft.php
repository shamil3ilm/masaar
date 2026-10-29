<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Services;

use App\Domains\Invoice\Exceptions\IdempotencyKeyReusedException;
use App\Domains\Invoice\Models\Invoice;
use App\Support\Errors\ComplianceException;
use App\Support\Errors\ErrorCode;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * A draft made once per Idempotency-Key.
 *
 * A caller that timed out sends the same request with the same key, and the
 * retry has to answer with what the first one made rather than make it
 * again: a second draft collides on the invoice number, or, numbered at
 * issue, bills the sale twice. The key is stored on the invoice, unique per
 * organization, so the first request's draft is what a retry finds, and
 * beside it a fingerprint of the request, so a key sent again with another
 * request is refused rather than answered with the first one's invoice.
 *
 * Shared by every endpoint that drafts - /api/invoices, the pipeline and the
 * batch - so a key means one thing wherever it is sent.
 */
class IdempotentDraft
{
    /** invoices.idempotency_key holds this many characters. */
    public const MAX_KEY_LENGTH = 255;

    public function __construct(
        private readonly InvoiceDrafter $drafter,
        private readonly InvoiceNumbering $numbering,
    ) {}

    /**
     * What an earlier request with this key drafted, checked to be this
     * request's; null when the key is new or none was sent. A blank header
     * is the caller's to treat as none.
     *
     * @param  array<string, mixed>  $data  The request as the client sent it (sent())
     *
     * @throws ValidationException When the key is unusable, or was used for another invoice
     */
    public function earlier(string $organizationId, ?string $key, array $data): ?Invoice
    {
        if ($key === null) {
            return null;
        }

        $this->assertUsable($key);

        $earlier = $this->drafter->createdUnder($organizationId, $key);

        if ($earlier !== null) {
            $this->assertSameRequest($earlier, $data);
        }

        return $earlier;
    }

    /**
     * Draft under the key. Two requests with one key can both pass
     * earlier(); the unique index lets one draft, and the other answers with
     * that one. Which happened is the invoice's wasRecentlyCreated.
     *
     * @param  array<string, mixed>  $draft  As InvoiceDrafter::draft takes it
     * @param  array<string, mixed>  $countryFields
     * @param  array<string, mixed>|null  $request  The request as the client sent it (sent()), when not the draft
     *
     * @throws ValidationException When the key is unusable, or was used for another invoice
     * @throws ComplianceException VAL_INVOICE_NUMBER_TAKEN when the number is the organization's already
     */
    public function draft(array $draft, string $organizationId, ?string $branchId, array $countryFields, ?string $key, ?string $tillId = null, ?array $request = null): Invoice
    {
        $request ??= $draft;

        if ($key !== null) {
            $this->assertUsable($key);
        }

        try {
            return $this->drafter->draft([
                ...$draft,
                'idempotency_key' => $key,
                'idempotency_hash' => $key === null ? null : self::fingerprint($request),
            ], $organizationId, $branchId, $countryFields, $tillId);
        } catch (UniqueConstraintViolationException $e) {
            $earlier = $key === null ? null : $this->drafter->createdUnder($organizationId, $key);

            if ($earlier === null) {
                throw $this->numberTaken($organizationId, $draft['invoice_number'] ?? null) ?? $e;
            }

            $this->assertSameRequest($earlier, $request);

            return $earlier;
        }
    }

    /**
     * A key names one request. Sent with a different invoice it is an
     * integrator's bug, and answering with the other invoice would hide it.
     *
     * The request has to be the first one, field for field: the fingerprint
     * kept with the key. An invoice drafted before fingerprints were kept,
     * or found by its ERP reference with no key sent, is compared as it was:
     * the request's own number, or failing that its ERP reference, has to be
     * the first call's.
     *
     * @param  array<string, mixed>  $data
     * @param  bool  $underKey  Whether the request sent the key the invoice was drafted under
     *
     * @throws IdempotencyKeyReusedException
     */
    public function assertSameRequest(Invoice $invoice, array $data, bool $underKey = true): void
    {
        if ($underKey && $invoice->idempotency_hash !== null) {
            if (! hash_equals($invoice->idempotency_hash, self::fingerprint($data))) {
                throw IdempotencyKeyReusedException::for($invoice->invoice_number);
            }

            return;
        }

        $sent = $data['invoice_number'] ?? null;
        $reference = $data['erp_reference_id'] ?? null;

        if (filled($sent) ? $invoice->invoice_number !== $sent : (filled($reference) && $invoice->erp_reference_id !== $reference)) {
            throw IdempotencyKeyReusedException::for($invoice->invoice_number);
        }
    }

    /**
     * The body as the client sent it, before the server filled anything in.
     *
     * A form request adds its defaults to the input - an issue date of
     * today when none was sent - so a fingerprint of the validated request
     * changed at midnight, and a retry sent unchanged after it was refused as
     * a reused key. The raw body is what the client controls.
     *
     * @return array<string, mixed>
     */
    public static function sent(Request $request): array
    {
        $body = json_decode((string) $request->getContent(), true);

        return is_array($body) ? $body : $request->request->all();
    }

    /**
     * SHA-256 of the request in a canonical form: object keys sorted at
     * every depth, so a retry that serialises its fields in another order is
     * still the same request. List order is kept, because the order of lines
     * is part of the invoice.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fingerprint(array $data): string
    {
        return hash('sha256', (string) json_encode(self::canonical($data), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
    }

    private static function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return array_map(self::canonical(...), $value);
    }

    /**
     * The refusal for a number the organization has used, or null when that
     * is not what the unique index refused.
     *
     * A till that resends without a key - its first answer lost to a
     * timeout - meets its own invoice. It was a 500 from the index; it is a
     * conflict the till can read: the sale is on the books under that number.
     */
    private function numberTaken(string $organizationId, mixed $number): ?ComplianceException
    {
        if (blank($number) || ! $this->numbering->taken($organizationId, (string) $number)) {
            return null;
        }

        return new ComplianceException(
            sprintf('The organization already has an invoice numbered %s. Read it rather than sending it again, or send this one under another number.', $number),
            ErrorCode::VAL_INVOICE_NUMBER_TAKEN,
        );
    }

    /**
     * @throws ValidationException
     */
    private function assertUsable(string $key): void
    {
        if (mb_strlen($key) > self::MAX_KEY_LENGTH) {
            throw ValidationException::withMessages([
                'idempotency_key' => __('The Idempotency-Key header may be at most :max characters.', ['max' => self::MAX_KEY_LENGTH]),
            ]);
        }
    }
}
