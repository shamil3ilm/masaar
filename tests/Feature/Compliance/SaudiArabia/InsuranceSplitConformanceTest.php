<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance\SaudiArabia;

use App\Domains\Auth\Models\User;
use App\Domains\Compliance\Countries\SaudiArabia\Signing\CredentialStore;
use App\Domains\Compliance\Engine\DTOs\IssueOptions;
use App\Domains\Invoice\Models\Customer;
use App\Domains\Invoice\Models\InsuranceSplit;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Invoice\Services\InsuranceSplits;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\SigningCredentials;
use Tests\Fixtures\ZatcaSdk;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * A sale split between a patient and their insurer, the whole way an
 * integrator sends it - POST /api/invoices/splits, the Saudi rules, both
 * shares drafted and issued - judged by ZATCA's validator: the patient's
 * simplified invoice, the insurer's tax invoice, and when the insurer
 * declines part of its share, the credit note on its invoice and the
 * patient's new invoice.
 *
 * Set ZATCA_SDK_PATH to run these; they skip without it.
 */
class InsuranceSplitConformanceTest extends TestCase
{
    use RefreshDatabase;
    use SigningCredentials;
    use ZatcaSdk;

    private Organization $organization;

    private Customer $insurer;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('creds');
        config(['fatoora.signing.disk' => 'creds']);

        $this->organization = Organization::create([
            'name' => 'Al Shifa Clinic',
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
        $credentials = $authority ?? $this->selfSignedCredentials();

        app(CredentialStore::class)->put($this->organization->id, null, CredentialStore::PCSID, [
            'privateKey' => $credentials['privateKey'], 'pcsid' => $credentials['certificate'], 'secret' => 'device-secret',
        ]);

        $this->insurer = new Customer([
            'org_id' => $this->organization->id, 'name' => 'Gulf Health Insurance', 'name_ar' => 'الخليج للتأمين الصحي',
            'vat_number' => '399999999800003', 'is_insurer' => true, 'street' => 'Olaya Street', 'building_number' => '4321',
            'district' => 'Al Murooj', 'city' => 'Riyadh', 'postal_code' => '11564', 'country_code' => 'SA',
        ]);
        Customer::withoutTenantScope(fn () => $this->insurer->save());
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function sales(): iterable
    {
        // A pharmacy's basket: a medicine zero-rated as VATEX-SA-35 and a
        // standard-rated item, 20% each from the patient, prices with VAT.
        yield 'medicines zero-rated, by percentage' => [[
            'prices_include_vat' => true,
            'lines' => [
                ['description' => 'Insulin pen', 'description_ar' => 'قلم إنسولين', 'quantity' => 3, 'unit_price' => '96.35', 'exempt_code' => 'VATEX-SA-35'],
                ['description' => 'Glucose strips', 'description_ar' => 'شرائط قياس السكر', 'quantity' => 1, 'unit_price' => '57.50'],
            ],
            'split' => ['method' => 'percent', 'percent' => 20],
        ]];
        // A fixed co-payment that does not divide by the quantity.
        yield 'medicines zero-rated, a fixed amount' => [[
            'lines' => [
                ['description' => 'Antibiotic', 'description_ar' => 'مضاد حيوي', 'quantity' => 3, 'unit_price' => '10.00', 'exempt_code' => 'VATEX-SA-35'],
                ['description' => 'Dressing', 'description_ar' => 'ضماد', 'quantity' => 1, 'unit_price' => '70.00'],
            ],
            'split' => ['method' => 'amount', 'amount' => '27.50'],
        ]];
        // A citizen's private healthcare: zero-rated for them by national
        // ID (BR-KSA-49, BR-KSA-25), standard-rated for the insurer. Beside
        // it a standard-rated item: one zero-rating reason per invoice.
        yield 'citizen healthcare with a national ID' => [[
            'buyer_name' => 'Fahad Al Harbi',
            'buyer_name_ar' => 'فهد الحربي',
            'buyer_id' => '1047392816',
            'buyer_id_scheme' => 'NAT',
            'lines' => [
                ['description' => 'Specialist consultation', 'description_ar' => 'استشارة أخصائي', 'quantity' => 1, 'unit_price' => '450.00', 'exempt_code' => 'VATEX-SA-HEA'],
                ['description' => 'Arm sling', 'description_ar' => 'حمالة ذراع', 'quantity' => 2, 'unit_price' => '35.00'],
            ],
            'split' => ['method' => 'percent', 'percent' => 15],
        ]];
    }

    /**
     * @param  array<string, mixed>  $sale
     */
    #[DataProvider('sales')]
    public function test_both_shares_validate(array $sale): void
    {
        $split = $this->issuedSplit($sale);

        foreach ([InsuranceSplit::PATIENT, InsuranceSplit::INSURER] as $role) {
            $this->assertAccepted((string) $split->share($role)->signed_xml, "the {$role}'s share");
        }

        $patient = $split->share(InsuranceSplit::PATIENT);
        $insurer = $split->share(InsuranceSplit::INSURER);

        $this->assertStringContainsString('<cbc:InvoiceTypeCode name="0200000">388</cbc:InvoiceTypeCode>', (string) $patient->signed_xml);
        $this->assertStringContainsString('<cbc:InvoiceTypeCode name="0100000">388</cbc:InvoiceTypeCode>', (string) $insurer->signed_xml);
        // The insurer's names the patient's, and carries the claim (BT-13).
        $this->assertStringContainsString('invoice '.$patient->invoice_number.'.</cbc:Note>', (string) $insurer->signed_xml);
        $this->assertMatchesRegularExpression('#<cac:OrderReference[^>]*>\s*<cbc:ID[^>]*>CLM-2026-0042</cbc:ID>#', (string) $insurer->signed_xml);
        $this->assertChained();
    }

    /**
     * @param  array<string, mixed>  $sale
     */
    private function issuedSplit(array $sale): InsuranceSplit
    {
        $this->requireSdk();

        $user = User::factory()->create();
        $user->organizations()->attach($this->organization->id, ['role' => 'admin', 'status' => 'active']);
        $token = JWTAuth::claims(['org_id' => $this->organization->id, 'role' => 'admin'])->fromUser($user);

        $response = $this->withToken($token)->postJson('/api/invoices/splits', [
            'issue_date' => now()->toDateString(),
            'supply_date' => now()->toDateString(),
            ...$sale,
            'split' => [...$sale['split'], 'insurer_customer_id' => $this->insurer->id, 'claim_reference' => 'CLM-2026-0042', 'policy_number' => 'POL-77', 'member_id' => 'MEM-1234'],
        ]);
        $this->assertSame(201, $response->status(), (string) $response->getContent());

        $split = InsuranceSplit::withoutTenantScope(fn () => InsuranceSplit::query()->findOrFail($response->json('data.split.id')));
        $outcomes = app(InsuranceSplits::class)->issue($split, $this->organization, new IssueOptions(file: false));

        $this->assertTrue($outcomes['patient']->issued && $outcomes['insurer']?->issued, 'a share was not issued');

        return $split->fresh('invoices');
    }

    /**
     * ZATCA's content checks passed, no business rule broken and no
     * advisory. The SDK's PIH stage compares against a file of its own, so
     * a document after the first in the chain is read for its predecessor
     * here instead (TillConformanceTest): each names the hash of the one
     * issued before it.
     */
    private function assertAccepted(string $xml, string $what): void
    {
        $this->assertNotSame('', $xml, "{$what} was not signed");

        $result = $this->validate($xml);

        $this->assertTrue($this->sdk()->contentPassed($result), "ZATCA rejected {$what}:
".implode('
', $result['errors']));
        $this->assertSame([], array_values(array_filter($result['errors'], static fn (string $error): bool => str_starts_with($error, 'BR-'))), "ZATCA rejected {$what}.");
        $this->assertSame([], $result['warnings'], "an advisory fired on {$what}.");
    }

    /** Every document of the organization's chain names the one before it. */
    private function assertChained(): void
    {
        $previous = null;

        foreach (Invoice::withoutTenantScope(fn () => Invoice::query()->whereNotNull('icv')->orderBy('icv')->get()) as $invoice) {
            if ($previous !== null) {
                $this->assertSame($previous->hash, $invoice->previous_invoice_hash, "{$invoice->invoice_number} does not name {$previous->invoice_number}");
                $this->assertStringContainsString((string) $previous->hash, (string) $invoice->signed_xml);
            }

            $previous = $invoice;
        }
    }
}
