<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Services;

use App\Domains\Invoice\Models\Invoice;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;

/**
 * A draft made once per Idempotency-Key.
 *
 * A caller that timed out sends the same request with the same key, and the
 * retry has to answer with what the first one made rather than make it
 * again: a second draft collides on the invoice number, or, numbered at
 * issue, bills the sale twice. The key is stored on the invoice, unique per
 * organization, so the first request's draft is what a retry finds.
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
    ) {}

    /**
     * What an earlier request with this key drafted, checked to be this
     * request's; null when the key is new or none was sent. A blank header
     * is the caller's to treat as none.
     *
     * @param  array<string, mixed>  $data  The request, as validated
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
     *
     * @throws ValidationException When the key is unusable, or was used for another invoice
     */
    public function draft(array $draft, string $organizationId, ?string $branchId, array $countryFields, ?string $key, ?string $tillId = null): Invoice
    {
        if ($key !== null) {
            $this->assertUsable($key);
        }

        try {
            return $this->drafter->draft([...$draft, 'idempotency_key' => $key], $organizationId, $branchId, $countryFields, $tillId);
        } catch (UniqueConstraintViolationException $e) {
            $earlier = $key === null ? null : $this->drafter->createdUnder($organizationId, $key);

            if ($earlier === null) {
                throw $e;
            }

            $this->assertSameRequest($earlier, $draft);

            return $earlier;
        }
    }

    /**
     * A key names one request. Sent with a different invoice it is an
     * integrator's bug, and answering with the other invoice would hide it.
     *
     * The request's own number, or failing that its ERP reference, has to be
     * the first call's. A request that sent no number was numbered at issue,
     * so its number is compared with nothing.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function assertSameRequest(Invoice $invoice, array $data): void
    {
        $sent = $data['invoice_number'] ?? null;
        $reference = $data['erp_reference_id'] ?? null;

        if (filled($sent) ? $invoice->invoice_number !== $sent : (filled($reference) && $invoice->erp_reference_id !== $reference)) {
            throw ValidationException::withMessages([
                'invoice_number' => 'This Idempotency-Key was already used for invoice '.$invoice->invoice_number
                    .'. Send a new key for a new invoice.',
            ]);
        }
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
