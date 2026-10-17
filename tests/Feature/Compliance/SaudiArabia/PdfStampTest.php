<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance\SaudiArabia;

use App\Domains\Compliance\Countries\SaudiArabia\Exceptions\SigningException;
use App\Domains\Compliance\Countries\SaudiArabia\Signing\CredentialStore;
use App\Domains\Compliance\Countries\SaudiArabia\Signing\PdfStamp;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Invoice\Models\InvoiceLine;
use App\Domains\Invoice\Services\InvoicePdf;
use App\Domains\Organization\Models\Branch;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use phpseclib3\File\ASN1;
use setasign\Fpdi\PdfParser\PdfParser;
use setasign\Fpdi\PdfParser\StreamReader;
use setasign\Fpdi\PdfParser\Type\PdfDictionary;
use setasign\Fpdi\PdfParser\Type\PdfType;
use Tests\Fixtures\SigningCredentials;
use Tests\TestCase;

/**
 * The PAdES stamp ZATCA requires on a PDF/A-3 invoice (Security Features
 * standard, 2.2.1 items 10, 11 and 15; 2.3.4).
 *
 * Checked the way a verifier would rather than by reading back what was
 * written: OpenSSL verifies the CMS against the bytes the ByteRange names,
 * and the signed attributes are decoded and checked one by one.
 */
class PdfStampTest extends TestCase
{
    use RefreshDatabase;
    use SigningCredentials;

    private const XML = '<?xml version="1.0" encoding="UTF-8"?><Invoice>signed</Invoice>';

    private Organization $acme;

    /** @var array{privateKey: string, certificate: string} */
    private array $head;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('creds');
        config(['fatoora.signing.disk' => 'creds']);

        $this->acme = Organization::create([
            'name' => 'Acme Trading',
            'name_ar' => 'شركة أكمي للتجارة',
            'country' => 'SA',
            'vat_number' => '300000000000003',
            'street' => 'King Fahd Road',
            'building_number' => '1234',
            'district' => 'Olaya',
            'city' => 'Riyadh',
            'postal_code' => '12345',
        ]);

        $this->head = $this->selfSignedCredentials(0x1234567890);
        $this->store(null, $this->head);
    }

    /**
     * OpenSSL verifies the CMS over exactly the bytes the ByteRange names.
     */
    public function test_openssl_verifies_the_stamp(): void
    {
        $pdf = $this->render();

        $this->assertTrue($this->opensslVerifies($this->signedBytes($pdf), $this->cms($pdf), $signer), (string) openssl_error_string());
        $this->assertSame($this->der($this->head['certificate']), $this->der((string) $signer));
    }

    /**
     * A single changed byte anywhere the signature covers fails it.
     */
    public function test_a_changed_byte_fails_it(): void
    {
        $pdf = $this->render();
        $tampered = substr_replace($pdf, 'X', 200, 1);

        $this->assertFalse($this->opensslVerifies($this->signedBytes($tampered), $this->cms($tampered)));
    }

    /**
     * The whole file but the /Contents hex string, angle brackets included.
     */
    public function test_byte_range_covers_all_but_contents(): void
    {
        $pdf = $this->render();
        [$first, $start, $end, $rest] = $this->byteRange($pdf);

        $this->assertSame(0, $first);
        $this->assertSame(strlen($pdf), $end + $rest);
        $this->assertMatchesRegularExpression('/^<[0-9a-f]+>$/', substr($pdf, $start, $end - $start));
        $this->assertStringEndsWith('/Contents ', substr($pdf, 0, $start));
    }

    /**
     * Section 2.3.4: content-type id-data, message-digest, ESS
     * signing-certificate-v2 - and no signing-time, which PAdES baseline
     * forbids in favour of /M - signed with ECDSA over SHA-256.
     */
    public function test_signed_attributes_follow_the_standard(): void
    {
        $pdf = $this->render();
        $cms = $this->cms($pdf);
        $signedData = ASN1::decodeBER($cms)[0]['content'][1]['content'][0]['content'];
        $signerInfo = $signedData[4]['content'][0]['content'];
        $certificate = $this->der($this->head['certificate']);

        $this->assertSame('1.2.840.113549.1.7.2', ASN1::decodeBER($cms)[0]['content'][0]['content']);
        $this->assertSame('2.16.840.1.101.3.4.2.1', $signedData[1]['content'][0]['content'][0]['content']);
        $this->assertSame('1.2.840.113549.1.7.1', $signedData[2]['content'][0]['content']);
        $this->assertCount(1, $signedData[2]['content'], 'detached: no eContent');
        $this->assertSame($certificate, $this->slice($cms, $signedData[3]['content'][0]));
        $this->assertSame('1.2.840.10045.4.3.2', $signerInfo[4]['content'][0]['content']);

        $attributes = [];
        foreach ($signerInfo[3]['content'] as $attribute) {
            $attributes[$attribute['content'][0]['content']] = $attribute['content'][1]['content'][0];
        }

        $this->assertEqualsCanonicalizing(
            ['1.2.840.113549.1.9.3', '1.2.840.113549.1.9.4', '1.2.840.113549.1.9.16.2.47'],
            array_keys($attributes),
        );
        $this->assertSame('1.2.840.113549.1.7.1', $attributes['1.2.840.113549.1.9.3']['content']);
        $this->assertSame(hash('sha256', $this->signedBytes($pdf), true), $attributes['1.2.840.113549.1.9.4']['content']);

        $essCertId = $attributes['1.2.840.113549.1.9.16.2.47']['content'][0]['content'][0]['content'];
        $this->assertSame(hash('sha256', $certificate, true), $essCertId[0]['content']);
        $this->assertSame(
            openssl_x509_parse($this->head['certificate'])['serialNumberHex'],
            strtoupper($essCertId[1]['content'][1]['content']->toHex()),
        );

        // The signature is over the attributes as a SET (tag 0x31), not [0].
        $signed = "\x31".substr($this->slice($cms, $signerInfo[3]), 1);
        $this->assertSame(1, openssl_verify($signed, $signerInfo[5]['content'], $this->head['certificate'], OPENSSL_ALGO_SHA256));
    }

    /**
     * Filter, SubFilter and /M as 2.3.4 lists them; an invisible, printable
     * widget on page 1; and an update a parser can follow back.
     */
    public function test_the_dictionary_is_pades(): void
    {
        $pdf = $this->render();

        $this->assertMatchesRegularExpression('#/Type /Sig /Filter /Adobe\.PPKLite /SubFilter /ETSI\.CAdES\.detached /ByteRange#', $pdf);
        $this->assertMatchesRegularExpression("#/M \(D:\d{14}\+00'00'\)#", $pdf);
        $this->assertStringContainsString('/FT /Sig', $pdf);
        $this->assertStringContainsString('/F 132 /Rect [0 0 0 0]', $pdf);

        $parser = new PdfParser(StreamReader::createByString($pdf));
        $trailer = $parser->getCrossReference()->getTrailer();
        $catalog = PdfDictionary::ensure(PdfType::resolve(PdfDictionary::get($trailer, 'Root'), $parser));
        $form = PdfDictionary::ensure($catalog->value['AcroForm']);
        $pages = PdfDictionary::ensure(PdfType::resolve($catalog->value['Pages'], $parser));
        $page = PdfDictionary::ensure(PdfType::resolve($pages->value['Kids']->value[0], $parser));

        $this->assertSame(3, $form->value['SigFlags']->value);
        $this->assertSame($form->value['Fields']->value[0]->value, $page->value['Annots']->value[0]->value);
        $this->assertArrayHasKey('Prev', $trailer->value);
        $this->assertArrayHasKey('ID', $trailer->value);
    }

    /**
     * Appended, not rewritten: the renderer's file - the embedded XML and
     * the PDF/A declaration with it - is there byte for byte.
     */
    public function test_the_xml_survives_the_stamp(): void
    {
        $this->store(null, null);
        $unsigned = $this->render();

        $signed = app(PdfStamp::class)->sign($unsigned, $this->head['privateKey'], $this->head['certificate']);

        $this->assertStringStartsWith($unsigned, $signed);
        $this->assertStringContainsString('/AFRelationship /Alternative', $signed);
        $this->assertStringContainsString('ACME-1.xml', $signed);
        $this->assertStringContainsString('pdfaid:part>3<', $signed);
        $this->assertStringContainsString('/Names << /EmbeddedFiles << /Names [(ACME-1.xml)', $signed);
    }

    /**
     * Phase 1: no certificate, so nothing to stamp with - and no reason to
     * keep the invoice from the buyer.
     */
    public function test_phase_one_stays_unsigned(): void
    {
        $this->store(null, null);

        $pdf = $this->render();

        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertStringNotContainsString('/ByteRange', $pdf);
    }

    public function test_a_branch_stamps_with_its_own(): void
    {
        $jeddah = $this->selfSignedCredentials();
        $branch = Branch::withoutTenantScope(fn () => Branch::create([
            'org_id' => $this->acme->id,
            'name' => 'Jeddah Branch',
            'device_serial' => 'EGS-JED-1',
            'street' => 'Corniche Road',
            'building_number' => '4321',
            'district' => 'Al Hamra',
            'city' => 'Jeddah',
            'postal_code' => '23234',
        ]));
        $this->store($branch->id, $jeddah);

        $pdf = $this->render(['branch_id' => $branch->id]);

        $this->assertTrue($this->opensslVerifies($this->signedBytes($pdf), $this->cms($pdf), $signer));
        $this->assertSame($this->der($jeddah['certificate']), $this->der((string) $signer));
    }

    /**
     * A key that is not the certificate's would sign something that verifies
     * against nothing.
     */
    public function test_a_mismatched_key_is_refused(): void
    {
        $this->expectException(SigningException::class);

        app(PdfStamp::class)->sign($this->render(), $this->selfSignedCredentials()['privateKey'], $this->head['certificate']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function render(array $overrides = []): string
    {
        return app(InvoicePdf::class)->render($this->invoice($overrides), $this->acme);
    }

    /**
     * @param  array{privateKey: string, certificate: string}|null  $credentials
     */
    private function store(?string $branchId, ?array $credentials): void
    {
        if ($credentials === null) {
            app(CredentialStore::class)->forget($this->acme->id, $branchId);

            return;
        }

        app(CredentialStore::class)->put($this->acme->id, $branchId, CredentialStore::PCSID, [
            'privateKey' => $credentials['privateKey'],
            'pcsid' => $credentials['certificate'],
            'secret' => 'secret',
        ]);
    }

    /**
     * @return array{0: int, 1: int, 2: int, 3: int}
     */
    private function byteRange(string $pdf): array
    {
        $this->assertSame(1, preg_match('#/ByteRange \[(\d+) (\d+) (\d+) (\d+)\s*\]#', $pdf, $match));

        return [(int) $match[1], (int) $match[2], (int) $match[3], (int) $match[4]];
    }

    private function signedBytes(string $pdf): string
    {
        [, $start, $end, $rest] = $this->byteRange($pdf);

        return substr($pdf, 0, $start).substr($pdf, $end, $rest);
    }

    /**
     * The CMS DER from /Contents, without the zero padding after it.
     */
    private function cms(string $pdf): string
    {
        [, $start, $end] = $this->byteRange($pdf);
        $der = (string) hex2bin(substr($pdf, $start + 1, $end - $start - 2));
        $node = ASN1::decodeBER($der)[0];

        return substr($der, 0, $node['length']);
    }

    private function opensslVerifies(string $content, string $cms, ?string &$signer = null): bool
    {
        $dir = sys_get_temp_dir();
        [$contentFile, $cmsFile, $signerFile] = [tempnam($dir, 'pdf'), tempnam($dir, 'cms'), tempnam($dir, 'crt')];

        try {
            file_put_contents($contentFile, $content);
            file_put_contents($cmsFile, $cms);

            $verified = openssl_cms_verify(
                $contentFile,
                OPENSSL_CMS_DETACHED | OPENSSL_CMS_BINARY | OPENSSL_CMS_NOVERIFY,
                $signerFile,
                sigfile: $cmsFile,
                encoding: OPENSSL_ENCODING_DER,
            );
            $signer = (string) file_get_contents($signerFile);

            return $verified;
        } finally {
            array_map(unlink(...), [$contentFile, $cmsFile, $signerFile]);
        }
    }

    /**
     * @param  array{start: int, length: int}  $node
     */
    private function slice(string $der, array $node): string
    {
        return substr($der, $node['start'], $node['length']);
    }

    private function der(string $pem): string
    {
        $this->assertSame(1, preg_match('/-----BEGIN CERTIFICATE-----(.+?)-----END CERTIFICATE-----/s', $pem, $match));

        return (string) base64_decode((string) preg_replace('/\s+/', '', $match[1]));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function invoice(array $overrides = []): Invoice
    {
        $invoice = Invoice::withoutTenantScope(fn () => Invoice::query()->create([
            'org_id' => $this->acme->id,
            'invoice_number' => 'ACME-1',
            'type' => 'simplified',
            'document_type' => 'invoice',
            'status' => 'draft',
            'issue_date' => now()->toDateString(),
            'currency' => 'SAR',
            'subtotal' => '100.00',
            'tax_amount' => '15.00',
            'total' => '115.00',
        ]));

        InvoiceLine::query()->create([
            'invoice_id' => $invoice->id,
            'description' => 'Consulting hour',
            'quantity' => '1',
            'unit_code' => 'HUR',
            'unit_price' => '100.00',
            'tax_rate' => '15',
            'tax_amount' => '15.00',
            'line_total' => '115.00',
        ]);

        $invoice->forceFill(array_merge([
            'status' => 'issued',
            'icv' => 1,
            'hash' => base64_encode(hash('sha256', 'acme', true)),
            'qr_code' => base64_encode('AQVTZWxsZXI='),
            'signed_xml' => self::XML,
        ], $overrides))->save();

        return $invoice->fresh(['lines']);
    }
}
