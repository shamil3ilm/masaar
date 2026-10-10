<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Domains\Auth\Models\User;
use App\Domains\Compliance\Fatoora\Services\DocumentBuilder;
use App\Domains\Compliance\Fatoora\Services\InvoiceHasher;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\SigningCredentials;
use Tests\TestCase;

/**
 * Both digests a real invoice carries describe the document that is sent.
 *
 * `EmittedSignatureTest` asserts this for the onboarding command, which is
 * what submits to the sandbox. This asserts it for the path that will sign
 * actual invoices - `DocumentBuilder`, reached through the API - and that is a
 * different path with its own QR insertion. The two insertions are duplicates
 * of each other and should become one service; until they are, each needs its
 * own assertion, because fixing one taught us nothing about the other.
 *
 * Two digests, because the authority checks two:
 *
 *   The xades:SignedProperties digest recorded in ds:SignedInfo. Re-indenting
 *   a signed document moved the bytes it describes, which is what the
 *   authority refused for weeks - see EmittedSignatureTest for that story.
 *
 *   The invoice hash, which rides in the submission and in QR tag 6. This
 *   path computes it from the *unsigned* document, before signing and before
 *   the QR goes in, and relies on the reference's XPath transforms to exclude
 *   both. That is the right design - the transforms exist so the hash does not
 *   move when a signature is added - but it only holds while nothing else
 *   changes the document on the way out. The onboarding command had to be
 *   changed to hash afterwards instead, because something did.
 */
class EmittedDigestsTest extends TestCase
{
    use RefreshDatabase;
    use SigningCredentials;

    private const DS = 'http://www.w3.org/2000/09/xmldsig#';

    private const XADES = 'http://uri.etsi.org/01903/v1.3.2#';

    private Organization $organization;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::create([
            'name' => 'Acme Trading',
            'country' => 'SA',
            'vat_number' => '300000000000003',
            'street' => 'King Fahd Road',
            'building_number' => '1234',
            'district' => 'Al Olaya',
            'city' => 'Riyadh',
            'postal_code' => '12345',
        ]);

        $user = User::factory()->create(['email' => 'biller@masaar.test']);
        $user->organizations()->attach($this->organization->id, [
            'role' => 'admin',
            'status' => 'active',
        ]);

        $this->token = $this->postJson('/api/auth/login', [
            'email' => 'biller@masaar.test',
            'password' => 'password',
        ])->json('data.token.access_token');
    }

    /**
     * Both kinds, because only a simplified document has its signature
     * verified by the authority - and that is exactly why the standard one
     * cleared throughout while the bug was live.
     *
     * @return list<array{0: string}>
     */
    public static function documents(): array
    {
        return [['simplified'], ['standard']];
    }

    #[DataProvider('documents')]
    public function test_the_recorded_digest_describes_the_block(string $type): void
    {
        $built = $this->build($type);
        $emitted = (string) ($built['signed_xml'] ?? $built['xml']);

        [$recorded, $block] = $this->signedProperties($emitted);

        $this->assertNotNull($recorded, 'The signature records no signed-properties digest.');

        $this->assertSame(
            $recorded,
            base64_encode(hash('sha256', $block)),
            "The {$type} document contradicts itself: the digest in ds:SignedInfo "
            .'does not describe the xades:SignedProperties block it carries. '
            .'Something changed the block after signing. The authority refuses '
            .'this, and only for a simplified document - so a passing standard '
            .'case proves nothing.'
        );
    }

    #[DataProvider('documents')]
    public function test_the_reported_hash_describes_the_document(string $type): void
    {
        $built = $this->build($type);
        $emitted = (string) ($built['signed_xml'] ?? $built['xml']);

        $this->assertSame(
            app(InvoiceHasher::class)->hash($emitted),
            (string) $built['hash'],
            "The {$type} document's hash was taken over something other than "
            .'what is emitted. It is computed before signing and before the QR '
            .'is inserted, which is sound only while the reference transforms '
            .'exclude both and nothing else moves. This is the hash the '
            .'authority recomputes and the one in QR tag 6.'
        );
    }

    /**
     * @return array{xml: string, hash: string, signed_xml: ?string}
     */
    private function build(string $type): array
    {
        $payload = [
            'invoice_number' => 'INV-'.uniqid(),
            'type' => $type,
            'issue_date' => now()->toDateString(),
            'buyer_name' => 'Buyer Co',
            'lines' => [[
                'description' => 'Item',
                'quantity' => 1,
                'unit_price' => '1000.00',
                'tax_rate' => '15',
                'tax_category' => 'S',
            ]],
        ];

        if ($type === 'standard') {
            $payload += [
                'buyer_vat_number' => '399999999800003',
                'buyer_address' => [
                    'street' => 'Prince Sultan Road',
                    'building_number' => '5678',
                    'district' => 'Al Malaz',
                    'city' => 'Riyadh',
                    'postal_code' => '12345',
                    'country_code' => 'SA',
                ],
            ];
        }

        $id = $this->withToken($this->token)
            ->postJson('/api/invoices', $payload)
            ->assertSuccessful()
            ->json('data.invoice.id');

        $invoice = Invoice::withoutTenantScope(fn () => Invoice::with('lines')->findOrFail($id));
        $credentials = $this->selfSignedCredentials();

        return app(DocumentBuilder::class)->generateComplianceData(
            invoice: $invoice,
            organization: $this->organization,
            previousInvoiceHash: null,
            privateKey: $credentials['privateKey'],
            certificate: $credentials['certificate'],
        );
    }

    /**
     * The recorded digest, and the bytes the authority digests to check it.
     *
     * @return array{0: ?string, 1: string}
     */
    private function signedProperties(string $xml): array
    {
        $dom = new DOMDocument;
        $dom->loadXML($xml);

        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('ds', self::DS);

        $recorded = null;

        foreach ($xpath->query('//ds:SignedInfo/ds:Reference') as $reference) {
            if ($reference->getAttribute('URI') === '#xadesSignedProperties') {
                $recorded = trim((string) $xpath->query('./ds:DigestValue', $reference)->item(0)?->textContent);
            }
        }

        $block = (string) $dom->saveXML($xpath->query("//*[local-name()='SignedProperties']")->item(0));

        // The authority's rule, confirmed against three of its own samples.
        if (! str_contains((string) strstr($block, '>', true), 'xmlns:xades=')) {
            $block = (string) preg_replace(
                '#^<xades:SignedProperties#',
                '<xades:SignedProperties xmlns:xades="'.self::XADES.'"',
                $block,
                1
            );
        }

        return [$recorded, (string) preg_replace(
            '#<ds:([A-Za-z0-9]+)(?![^>]*xmlns:ds=)#',
            '<ds:$1 xmlns:ds="'.self::DS.'"',
            $block
        )];
    }
}
