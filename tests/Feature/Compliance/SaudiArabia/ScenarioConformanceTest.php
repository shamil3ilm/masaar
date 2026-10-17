<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance\SaudiArabia;

use App\Domains\Auth\Models\User;
use App\Domains\Compliance\Countries\SaudiArabia\Filing\Submitter;
use App\Domains\Compliance\Countries\SaudiArabia\Signing\CredentialStore;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\SigningCredentials;
use Tests\Fixtures\ZatcaSdk;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * Business scenarios as a caller sends them, judged by ZATCA's validator.
 *
 * ZatcaConformanceTest writes most of its invoices straight into the table,
 * which proves the document is right for an invoice that is right. These go
 * the whole way an integrator does: POST /api/invoices, the Saudi rules, the
 * drafter, then the signed document - so a field the rules drop, or store
 * and never write, shows up here as the document ZATCA sees.
 *
 * Set ZATCA_SDK_PATH to run these; they skip without it.
 */
class ScenarioConformanceTest extends TestCase
{
    use RefreshDatabase;
    use SigningCredentials;
    use ZatcaSdk;

    private Organization $organization;

    private bool $signedByAuthority = false;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('creds');
        config(['fatoora.signing.disk' => 'creds']);

        $this->organization = Organization::create([
            'name' => 'Acme Trading',
            'country' => 'SA',
            'vat_number' => '399999999900003',
            'street' => 'King Fahd Road',
            'building_number' => '1234',
            'district' => 'Al Olaya',
            'city' => 'Riyadh',
            'postal_code' => '12345',
            'cr_number' => '1010101010',
            'compliance_profile' => ['zatca_onboarded' => true],
        ]);

        $authority = $this->authorityCredentials($this->sdk()->path());
        $this->signedByAuthority = $authority !== null;
        $credentials = $authority ?? $this->selfSignedCredentials();

        app(CredentialStore::class)->put(
            $this->organization->id,
            null,
            CredentialStore::PCSID,
            ['privateKey' => $credentials['privateKey'], 'pcsid' => $credentials['certificate'], 'secret' => 'device-secret']
        );
    }

    /**
     * KSA-2's transaction types, each in the shape ZATCA's own sample for it
     * has, with the type name that sample carries.
     *
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function transactionTypes(): iterable
    {
        yield 'export of goods' => [self::export('VATEX-SA-32'), '0100100'];
        yield 'export of services' => [self::export('VATEX-SA-33'), '0100100'];
        yield 'third party' => [self::standard(['is_third_party' => true]), '0110000'];
        yield 'nominal' => [self::simplified(['is_nominal' => true]), '0201000'];
        yield 'summary' => [self::standard(['is_summary' => true]), '0100010'];
        yield 'self billed' => [self::standard(['is_self_billed' => true]), '0100001'];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    #[DataProvider('transactionTypes')]
    public function test_transaction_types_validate(array $payload, string $typeName): void
    {
        $xml = $this->signedFrom($payload);

        $this->assertStringContainsString('<cbc:InvoiceTypeCode name="'.$typeName.'">', $xml);
        $this->assertAccepted($xml);
    }

    /**
     * A month's sales summarised on one simplified invoice: BR-KSA-72 wants
     * the period it covers, and the period sent through the API is the one
     * the document states.
     */
    public function test_a_simplified_summary_states_its_period(): void
    {
        $start = now()->startOfMonth()->toDateString();
        $end = now()->toDateString();

        $xml = $this->signedFrom(self::simplified([
            'is_summary' => true,
            'buyer_name' => 'Beta Industries',
            'supply_date' => $start,
            'supply_end_date' => $end,
        ]));

        $this->assertStringContainsString('<cbc:InvoiceTypeCode name="0200010">', $xml);
        $this->assertMatchesRegularExpression("#<cbc:ActualDeliveryDate[^>]*>{$start}</cbc:ActualDeliveryDate>#", $xml);
        $this->assertMatchesRegularExpression("#<cbc:LatestDeliveryDate[^>]*>{$end}</cbc:LatestDeliveryDate>#", $xml);
        $this->assertAccepted($xml);
    }

    /**
     * POST the payload to the API, then sign what was drafted.
     *
     * @param  array<string, mixed>  $payload
     */
    private function signedFrom(array $payload): string
    {
        $this->requireSdk();

        $user = User::factory()->create();
        $user->organizations()->attach($this->organization->id, ['role' => 'admin', 'status' => 'active']);
        $token = JWTAuth::claims(['org_id' => $this->organization->id, 'role' => 'admin'])->fromUser($user);

        $id = $this->withToken($token)->postJson('/api/invoices', [
            'invoice_number' => 'SCENARIO-1',
            'issue_date' => now()->toDateString(),
            'supply_date' => now()->toDateString(),
            ...$payload,
        ])->assertCreated()->json('data.invoice.id');

        $invoice = Invoice::withoutTenantScope(fn () => Invoice::with('lines')->findOrFail($id));
        $signed = app(Submitter::class)->generate($invoice, $this->organization)['signed_xml'];

        $this->assertNotEmpty($signed, 'the invoice was not signed');

        return $signed;
    }

    /**
     * No business rule broken and no advisory, the four signature and chain
     * checks a self-signed key cannot pass aside (see ZatcaConformanceTest).
     */
    private function assertAccepted(string $xml): void
    {
        $result = $this->validate($xml);

        $errors = $this->signedByAuthority
            ? array_values(array_diff($result['errors'], ['signatureValue: wrong signature Value']))
            : array_values(array_filter($result['errors'], static fn (string $error): bool => str_starts_with($error, 'BR-')));

        $this->assertSame([], $errors, 'ZATCA rejected the document.');
        $this->assertSame([], $result['warnings'], 'an advisory fired.');
    }

    /**
     * Goods or services to a business abroad, zero-rated as an export, as
     * ZATCA's export sample bills them.
     *
     * @return array<string, mixed>
     */
    private static function export(string $exemption): array
    {
        return self::standard([
            'is_export' => true,
            'buyer_vat_number' => null,
            'buyer_id' => 'US1234567891',
            'buyer_id_scheme' => 'OTH',
            'buyer_address' => [
                'street' => 'Canadian Street',
                'building_number' => '12',
                'city' => 'Houston',
                'postal_code' => '77001',
                'country_code' => 'US',
            ],
            'lines' => [[
                'description' => 'Printing equipment',
                'quantity' => 1,
                'unit_price' => '15000.00',
                'exempt_code' => $exemption,
                'exempt_reason' => $exemption === 'VATEX-SA-32' ? 'Export of goods' : 'Export of services',
            ]],
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private static function standard(array $overrides = []): array
    {
        return [
            'type' => 'standard',
            'buyer_name' => 'Beta Industries',
            'buyer_vat_number' => '399999999800003',
            'buyer_address' => [
                'street' => 'Olaya Street',
                'building_number' => '4321',
                'district' => 'Al Murooj',
                'city' => 'Riyadh',
                'postal_code' => '11564',
                'country_code' => 'SA',
            ],
            'lines' => [['description' => 'Consulting', 'quantity' => 1, 'unit_price' => '1000.00']],
            ...$overrides,
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private static function simplified(array $overrides = []): array
    {
        return [
            'type' => 'simplified',
            'lines' => [['description' => 'Gift', 'quantity' => 1, 'unit_price' => '300.00']],
            ...$overrides,
        ];
    }
}
