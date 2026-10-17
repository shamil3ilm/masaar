<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use App\Domains\Compliance\Countries\SaudiArabia\Models\ChainEntry;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Webhook\Jobs\DeliverWebhook;
use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;

/**
 * Snapshots of what Saudi issuing produces, compared byte for byte.
 *
 * Three things would make two runs differ, and each is pinned:
 *
 *   time         travelTo() a fixed instant, so issued_at, IssueTime, the QR
 *                timestamp and the XAdES SigningTime are the same every run.
 *   identifiers  Str::createUuidsUsing() with a counter, restarted in a new
 *                series before each document is drafted (useUuidSeries), so
 *                an invoice's UUID does not depend on how many identifiers
 *                were spent issuing the documents before it.
 *   keys         a fixed secp256k1 keypair and self-signed certificate per
 *                device, committed in tests/Fixtures/golden/keys. Test-only:
 *                self-signed, trusted by nothing, never used outside tests.
 *
 * ECDSA is not deterministic here - openssl_sign draws a fresh nonce - so
 * the signature value differs on every run, and two things carry it:
 *
 *   - ds:SignatureValue in the signed XML;
 *   - QR tag 7, which is that same value, and so the whole QR blob embedded
 *     in the XML and stored as qr_code.
 *
 * Those are masked, and only those. Nothing else depends on the signature:
 * the invoice hash is computed with the signature and the QR removed, and
 * the SignedInfo, SignedProperties and certificate digests are taken before
 * signing. What the mask hides is proven instead of compared: the signature
 * is verified with OpenSSL against the fixture certificate over the
 * canonical SignedInfo, tag 7 is asserted equal to ds:SignatureValue, the
 * embedded QR is asserted equal to qr_code, and the QR's other eight tags are
 * snapshotted decoded, along with a check that the TLV holds nothing else.
 *
 * Snapshots are rewritten only on request: GOLDEN_UPDATE=1. A rewritten case
 * is reported as skipped, so a regeneration can never pass as a green run,
 * and a missing snapshot fails rather than being written.
 */
trait GoldenMaster
{
    private const GOLDEN_DIR = __DIR__.'/golden';

    private const SIGNATURE_MASK = '[masked: ECDSA signature, randomized per run; verified with OpenSSL instead]';

    private const QR_MASK = '[masked: QR TLV, carries the signature as tag 7; decoded in the .json snapshot]';

    private const DS_NS = 'http://www.w3.org/2000/09/xmldsig#';

    /** @var list<string> cases rewritten under GOLDEN_UPDATE */
    private array $rewritten = [];

    private function useUuidSeries(int $series): void
    {
        $counter = 0;

        Str::createUuidsUsing(function () use ($series, &$counter) {
            return Uuid::fromString(sprintf('%08x-0000-4000-8000-%012x', $series, ++$counter));
        });
    }

    /**
     * @return array{privateKey: string, certificate: string}
     */
    private function goldenCredentials(string $device): array
    {
        return [
            'privateKey' => (string) file_get_contents(self::GOLDEN_DIR."/keys/{$device}.key.pem"),
            'certificate' => (string) file_get_contents(self::GOLDEN_DIR."/keys/{$device}.cert.pem"),
        ];
    }

    private function webhooksSoFar(): int
    {
        return Queue::pushed(DeliverWebhook::class)->count();
    }

    /**
     * The invoice.* webhooks queued since $from, in order.
     *
     * @return list<array{event: string, payload: array<string, mixed>}>
     */
    private function webhooksSince(int $from): array
    {
        return Queue::pushed(DeliverWebhook::class)
            ->slice($from)
            ->map(fn (DeliverWebhook $job): array => ['event' => $job->event, 'payload' => $job->payload])
            ->values()
            ->all();
    }

    /**
     * Check one issued document against its snapshot.
     *
     * @param  string  $device  The keypair that must have signed it.
     * @param  array<string, mixed>  $context  Entry-point output: pipeline
     *                                         result, HTTP answer, webhooks.
     */
    private function assertGolden(string $case, string $label, Invoice $invoice, string $device, array $context): void
    {
        $invoice = Invoice::withoutTenantScope(fn () => Invoice::query()->findOrFail($invoice->id));
        $xml = (string) $invoice->signed_xml;
        $qr = (string) $invoice->qr_code;

        $this->assertNotSame('', $xml, "{$case}/{$label}: the invoice was not signed");

        $signature = $this->assertSignatureVerifies($xml, $this->goldenCredentials($device)['certificate'], "{$case}/{$label}");
        $this->assertSame($this->independentHash($xml), $invoice->hash, "{$case}/{$label}: the stored hash is not the document's");

        $tags = $this->decodeTlv($qr, "{$case}/{$label}");
        $this->assertSame($signature, $tags[7] ?? null, "{$case}/{$label}: QR tag 7 is not ds:SignatureValue");
        $this->assertSame($invoice->hash, $tags[6] ?? null, "{$case}/{$label}: QR tag 6 is not the hash");
        $tags[7] = '[masked: equals ds:SignatureValue]';

        $masked = $this->replaceOnce("<ds:SignatureValue>{$signature}</ds:SignatureValue>", '<ds:SignatureValue>'.self::SIGNATURE_MASK.'</ds:SignatureValue>', $xml, "{$case}/{$label}: signature value");
        $masked = $this->replaceOnce('>'.$qr.'<', '>'.self::QR_MASK.'<', $masked, "{$case}/{$label}: embedded QR equal to qr_code");

        $snapshot = [
            'invoice' => [
                'invoice_number' => $invoice->invoice_number,
                'icv' => $invoice->icv,
                'device_id' => $invoice->device_id,
                'issued_at' => $invoice->issued_at?->format('Y-m-d\TH:i:sP'),
                'status' => $invoice->status->value,
                'type' => $invoice->type->value,
                'document_type' => $invoice->document_type?->value,
                'hash' => $invoice->hash,
                'currency' => $invoice->currency,
                'subtotal' => $invoice->subtotal,
                'discount_amount' => $invoice->discount_amount,
                'tax_amount' => $invoice->tax_amount,
                'total' => $invoice->total,
            ],
            // Tags 8 and 9 are raw DER, so they are written as base64.
            'qr' => array_combine(array_keys($tags), array_map(
                fn (int $tag, string $value): string => $tag >= 8 ? 'base64:'.base64_encode($value) : $value,
                array_keys($tags),
                $tags,
            )),
            'chain' => $this->chainRecord($invoice),
            ...$this->maskOutputs($context, $invoice),
        ];

        $this->compareWithGolden("{$case}/{$label}.xml", $masked);
        $this->compareWithGolden("{$case}/{$label}.json", json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
    }

    /**
     * Report a regeneration as a skip, once every document of the case ran.
     */
    private function finishGolden(): void
    {
        if ($this->rewritten !== []) {
            $this->markTestSkipped('GOLDEN_UPDATE: rewrote '.implode(', ', $this->rewritten));
        }
    }

    /**
     * Entry-point outputs carry the signed XML and QR again; they are
     * asserted equal to the stored ones and then named rather than repeated.
     *
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function maskOutputs(array $context, Invoice $invoice): array
    {
        array_walk_recursive($context, function (mixed &$value, string|int $key) use ($invoice): void {
            if ($key === 'signed_xml' && $value !== null) {
                $this->assertSame($invoice->signed_xml, $value, 'an entry point answered with a document other than the stored one');
                $value = '[equals invoice signed_xml]';
            }

            if ($key === 'qr_code' && $value !== null) {
                $this->assertSame($invoice->qr_code, $value, 'an entry point answered with a QR other than the stored one');
                $value = '[equals invoice qr_code]';
            }
        });

        return $context;
    }

    /**
     * @return array<string, mixed>
     */
    private function chainRecord(Invoice $invoice): array
    {
        $entry = ChainEntry::withoutTenantScope(fn () => ChainEntry::query()->where('invoice_id', $invoice->id)->firstOrFail());
        $head = DB::table('hash_chain_heads')->where('device_id', $entry->device_id)->first();

        return [
            'entry' => [
                'invoice_id' => $entry->invoice_id,
                'org_id' => $entry->org_id,
                'device_id' => $entry->device_id,
                'icv' => (int) $entry->icv,
                'invoice_hash' => $entry->invoice_hash,
                'previous_hash' => $entry->previous_hash,
                'certificate_id' => $entry->certificate_id,
            ],
            'head_after_case' => $head === null ? null : [
                'last_icv' => (int) $head->last_icv,
                'last_hash' => $head->last_hash,
                'last_invoice_id' => $head->last_invoice_id,
                'certificate_id' => $head->certificate_id,
            ],
        ];
    }

    /**
     * Verify ds:SignatureValue over the canonical SignedInfo with OpenSSL,
     * against the fixture certificate rather than whatever the XML embeds.
     */
    private function assertSignatureVerifies(string $xml, string $certificate, string $where): string
    {
        $dom = new DOMDocument;
        $this->assertTrue($dom->loadXML($xml), "{$where}: the signed XML does not parse");

        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('ds', self::DS_NS);

        $signedInfo = $xpath->query('//ds:Signature/ds:SignedInfo')->item(0);
        $value = $xpath->query('//ds:Signature/ds:SignatureValue')->item(0)?->textContent;

        $this->assertNotNull($signedInfo, "{$where}: no ds:SignedInfo");
        $this->assertNotNull($value, "{$where}: no ds:SignatureValue");

        $key = openssl_pkey_get_public($certificate);
        $this->assertNotFalse($key, "{$where}: the fixture certificate does not load");

        $this->assertSame(
            1,
            openssl_verify($signedInfo->C14N(false, false), (string) base64_decode($value, true), $key, OPENSSL_ALGO_SHA256),
            "{$where}: the signature does not verify with OpenSSL against the fixture certificate",
        );

        return $value;
    }

    /**
     * ZATCA's invoice hash, computed here rather than by InvoiceHasher: SHA-256
     * of the inclusive C14N of the document without UBLExtensions, the
     * signature and the QR reference.
     */
    private function independentHash(string $xml): string
    {
        $dom = new DOMDocument;
        $dom->preserveWhiteSpace = true;
        $dom->loadXML($xml);

        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('ext', 'urn:oasis:names:specification:ubl:schema:xsd:CommonExtensionComponents-2');
        $xpath->registerNamespace('cac', 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2');
        $xpath->registerNamespace('cbc', 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');

        foreach (['//ext:UBLExtensions', '//cac:Signature', '//cac:AdditionalDocumentReference[cbc:ID="QR"]'] as $path) {
            foreach (iterator_to_array($xpath->query($path)) as $node) {
                $node->parentNode->removeChild($node);
            }
        }

        return base64_encode(hash('sha256', $dom->documentElement->C14N(false, false), true));
    }

    /**
     * Decode the QR's TLV, and prove re-encoding it gives back every byte.
     *
     * @return array<int, string>
     */
    private function decodeTlv(string $base64, string $where): array
    {
        $raw = (string) base64_decode($base64, true);
        $tags = [];
        $offset = 0;

        while ($offset < strlen($raw)) {
            $tag = ord($raw[$offset]);
            $length = ord($raw[$offset + 1]);
            $tags[$tag] = substr($raw, $offset + 2, $length);
            $offset += 2 + $length;
        }

        $rebuilt = '';

        foreach ($tags as $tag => $value) {
            $rebuilt .= chr($tag).chr(strlen($value)).$value;
        }

        $this->assertSame($raw, $rebuilt, "{$where}: the QR holds bytes outside its tags");

        return $tags;
    }

    private function replaceOnce(string $search, string $replace, string $subject, string $what): string
    {
        $this->assertSame(1, substr_count($subject, $search), "{$what}: expected exactly once in the signed XML");

        return str_replace($search, $replace, $subject);
    }

    private function compareWithGolden(string $relative, string $actual): void
    {
        $path = self::GOLDEN_DIR.'/'.$relative;

        if (getenv('GOLDEN_UPDATE') === '1') {
            if (! is_dir(dirname($path))) {
                mkdir(dirname($path), 0777, true);
            }

            if (! is_file($path) || file_get_contents($path) !== $actual) {
                file_put_contents($path, $actual);
                $this->rewritten[] = $relative;
            }

            return;
        }

        $this->assertFileExists($path, "No golden snapshot at tests/Fixtures/golden/{$relative}. Run with GOLDEN_UPDATE=1 to write it, then review the diff.");

        $expected = (string) file_get_contents($path);
        $message = "Saudi issuing output changed: tests/Fixtures/golden/{$relative}. If deliberate, rerun with GOLDEN_UPDATE=1 and review the diff.";

        // The XML is one line, so a raw diff is unreadable: show it broken
        // between elements first, then hold it to the exact bytes.
        if (str_ends_with($relative, '.xml') && $expected !== $actual) {
            $this->assertSame(str_replace('><', ">\n<", $expected), str_replace('><', ">\n<", $actual), $message);
        }

        $this->assertSame($expected, $actual, $message);
    }
}
