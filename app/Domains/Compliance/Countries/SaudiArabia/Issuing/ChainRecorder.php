<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\SaudiArabia\Issuing;

use App\Domains\Compliance\Countries\SaudiArabia\Models\ChainEntry;
use App\Domains\Compliance\Countries\SaudiArabia\Models\ChainState;
use App\Domains\Compliance\Countries\SaudiArabia\Rules\FatooraConfig;
use App\Domains\Invoice\Models\Invoice;
use Illuminate\Support\Facades\DB;

/**
 * Records what each issued document was built with.
 *
 * ZATCA's chain is a claim every invoice makes about the one before it, and
 * the authority checks it. Invoice::getPreviousInvoiceHashAttribute() derives
 * that claim by re-walking the invoices table, which answers what the chain
 * *would* be from the rows that survive — not what a given document actually
 * carried when it was signed.
 *
 * The difference is the whole point of keeping this. A hash that changes, an
 * invoice deleted from beneath its successor, a document built during an
 * incident: derivation agrees with itself in every one of those cases, and
 * this does not. VerifyHashChain compares the two, so it can only find a break
 * that something recorded.
 *
 * Written at issuance, in the same transaction as the invoice, because a
 * document that exists without its chain entry is exactly the gap the
 * comparison is meant to catch.
 */
final class ChainRecorder
{
    /**
     * The value certificate_id carries for a document signed with no
     * certificate — a test run, or an organization that has not onboarded.
     *
     * The column is NOT NULL and holds a SHA-256 digest, so it needs a value
     * of that shape that no certificate can produce.
     */
    public const NO_CERTIFICATE = '0000000000000000000000000000000000000000000000000000000000000000';

    /**
     * Record one issued document and advance the tenant's chain head.
     *
     * Idempotent on the invoice: re-issuing the same document overwrites its
     * entry rather than adding a second one at the same ICV.
     */
    public function record(Invoice $invoice, string $invoiceHash, ?string $previousHash, ?string $certificate): void
    {
        $organizationId = (string) $invoice->org_id;
        $deviceId = (string) ($invoice->device_id ?? $invoice->org_id);
        $certificateId = $this->certificateId($certificate);

        // The genesis PIH, for the first document in a chain. XmlBuilder emits
        // this same value when it is handed a null, so the recorded entry says
        // what the document says.
        $previousHash ??= FatooraConfig::GENESIS_PIH;

        DB::transaction(function () use ($invoice, $invoiceHash, $previousHash, $certificateId, $organizationId, $deviceId): void {
            // org_id is given explicitly, so the scope would only decide
            // whether this row is visible to the caller writing it — and in a
            // queue there is no tenant to decide with.
            ChainEntry::acrossTenants()->updateOrCreate(
                ['invoice_id' => $invoice->id],
                [
                    'org_id' => $organizationId,
                    'device_id' => $deviceId,
                    'invoice_hash' => $invoiceHash,
                    'previous_hash' => $previousHash,
                    'icv' => (int) $invoice->icv,
                    'certificate_id' => $certificateId,
                ]
            );

            $state = ChainState::acrossTenants()->find($deviceId);

            // A chain head only moves forward. Re-issuing an earlier
            // document must not drag it back, or the next invoice chains
            // onto the wrong one.
            if ($state !== null && $state->last_icv > (int) $invoice->icv) {
                return;
            }

            ChainState::acrossTenants()->updateOrInsert(
                ['device_id' => $deviceId],
                [
                    'org_id' => $organizationId,
                    'last_hash' => $invoiceHash,
                    'last_icv' => (int) $invoice->icv,
                    'last_invoice_id' => $invoice->id,
                    'certificate_id' => $certificateId,
                    'updated_at' => now(),
                    // When the device's chain began: set once, with its first entry.
                    ...($state === null ? ['created_at' => now()] : []),
                ]
            );
        });
    }

    /**
     * Which certificate signed this document.
     *
     * A digest of the certificate rather than a reference to a row. Two
     * documents signed by the same certificate carry the same value, which is
     * what makes a certificate change visible in the chain - and the device's
     * certificate versions are keyed by this same digest, which is how a
     * document is filed with the certificate that signed it.
     */
    private function certificateId(?string $certificate): string
    {
        if ($certificate === null || $certificate === '') {
            return self::NO_CERTIFICATE;
        }

        return hash('sha256', $certificate);
    }
}
