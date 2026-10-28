<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance\SaudiArabia;

use App\Domains\Auth\Models\User;
use App\Domains\Compliance\Countries\SaudiArabia\Signing\CredentialStore;
use App\Domains\Compliance\Engine\DTOs\IssueOptions;
use App\Domains\Compliance\Engine\Services\ComplianceEngine;
use App\Domains\Invoice\Models\Customer;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Fixtures\SigningCredentials;
use Tests\Fixtures\ZatcaSdk;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * The documents the special transactions make, issued the whole way an
 * integrator asks for them and judged by ZATCA's own validator:
 *
 *   a month's summary tax invoice (IR Art 53(4); KSA-2 position 6, with
 *   KSA-5 and KSA-24 as the period);
 *   a contract stage invoiced net of retention, and the release of the
 *   retention on its own tax invoice (IR Art 20(2)), naming the contract
 *   (BT-12).
 *
 * Set ZATCA_SDK_PATH to run these; they skip without it.
 */
class SpecialTransactionConformanceTest extends TestCase
{
    use RefreshDatabase;
    use SigningCredentials;
    use ZatcaSdk;

    private Organization $organization;

    private Customer $customer;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('creds');
        config(['fatoora.signing.disk' => 'creds']);

        $this->organization = Organization::create([
            'name' => 'Riyadh Building Supplies', 'country' => 'SA', 'vat_number' => '399999999900003',
            'street' => 'King Fahd Road', 'building_number' => '1234', 'district' => 'Al Olaya', 'city' => 'Riyadh',
            'postal_code' => '12345', 'cr_number' => '1010101010', 'compliance_profile' => ['zatca_onboarded' => true],
        ]);

        $authority = $this->authorityCredentials($this->sdk()->path());
        $credentials = $authority ?? $this->selfSignedCredentials();
        app(CredentialStore::class)->put($this->organization->id, null, CredentialStore::PCSID, [
            'privateKey' => $credentials['privateKey'], 'pcsid' => $credentials['certificate'], 'secret' => 'device-secret',
        ]);

        $this->customer = new Customer([
            'org_id' => $this->organization->id, 'name' => 'Al Bina Contracting', 'name_ar' => 'البناء للمقاولات',
            'vat_number' => '399999999800003', 'street' => 'Olaya Street', 'building_number' => '4321',
            'district' => 'Al Murooj', 'city' => 'Riyadh', 'postal_code' => '11564', 'country_code' => 'SA',
        ]);
        Customer::withoutTenantScope(fn () => $this->customer->save());

        $user = User::factory()->create();
        $user->organizations()->attach($this->organization->id, ['role' => 'admin', 'status' => 'active']);
        $this->token = JWTAuth::claims(['org_id' => $this->organization->id, 'role' => 'admin'])->fromUser($user);
    }

    /** Two deliveries in one month on one summary invoice: 0100010. */
    public function test_summary_invoice_validates(): void
    {
        $this->requireSdk();
        $month = now()->subMonthNoOverflow();

        foreach ([[3, 'Cement bag', 'كيس أسمنت', '20.00', []], [10, 'Steel bar', 'قضيب حديد', '100.00', []]] as [$day, $description, $arabic, $price, $treatment]) {
            $this->withToken($this->token)->postJson('/api/delivery-notes', [
                'customer_id' => $this->customer->id, 'delivered_on' => $month->startOfMonth()->addDays($day)->toDateString(),
                'lines' => [['description' => $description, 'description_ar' => $arabic, 'quantity' => 2, 'unit_price' => $price, ...$treatment]],
            ])->assertCreated();
        }

        $response = $this->withToken($this->token)->postJson('/api/invoices/summary', [
            'customer_id' => $this->customer->id, 'from' => $month->startOfMonth()->toDateString(), 'to' => $month->endOfMonth()->toDateString(),
        ]);
        $this->assertSame(201, $response->status(), (string) $response->getContent());

        $xml = $this->issued($response->json('data.invoice.id'));

        $this->assertStringContainsString('<cbc:InvoiceTypeCode name="0100010">388</cbc:InvoiceTypeCode>', $xml);
        $this->assertMatchesRegularExpression('#<cbc:LatestDeliveryDate[^>]*>'.$month->endOfMonth()->toDateString().'</cbc:LatestDeliveryDate>#', $xml);
        $this->assertAccepted($xml, 'the summary invoice');
    }

    /** A stage billed net of 10% retention, then the retention released: both 0100000 tax invoices naming the contract. */
    public function test_stage_and_release_validate(): void
    {
        $this->requireSdk();

        $contract = $this->withToken($this->token)->postJson('/api/contracts', [
            'customer_id' => $this->customer->id, 'reference' => 'C-2026-07', 'title' => 'Villa construction', 'title_ar' => 'إنشاء فيلا',
            'value' => '100000', 'retention_percent' => '10',
            'stages' => [['name' => 'Foundations', 'name_ar' => 'الأساسات', 'percent' => '40'], ['name' => 'Structure', 'name_ar' => 'الهيكل', 'percent' => '60']],
        ]);
        $this->assertSame(201, $contract->status(), (string) $contract->getContent());
        $id = $contract->json('data.contract.id');

        $stage = $this->withToken($this->token)->postJson("/api/contracts/{$id}/stages/".$contract->json('data.statement.stages.0.id').'/invoice');
        $this->assertSame(201, $stage->status(), (string) $stage->getContent());
        $xml = $this->issued($stage->json('data.invoice.id'));
        $this->assertStringContainsString('<cbc:InvoiceTypeCode name="0100000">388</cbc:InvoiceTypeCode>', $xml);
        $this->assertMatchesRegularExpression('#<cac:ContractDocumentReference[^>]*>\s*<cbc:ID[^>]*>C-2026-07</cbc:ID>#', $xml);
        $this->assertAccepted($xml, 'the stage invoice');

        $release = $this->withToken($this->token)->postJson("/api/contracts/{$id}/release");
        $this->assertSame(201, $release->status(), (string) $release->getContent());
        $this->assertSame('4000.00', (string) $release->json('data.invoice.subtotal'));
        $this->assertAccepted($this->issued($release->json('data.invoice.id')), 'the retention release');
    }

    private function issued(string $invoiceId): string
    {
        $invoice = Invoice::withoutTenantScope(fn () => Invoice::query()->findOrFail($invoiceId));
        $outcome = app(ComplianceEngine::class)->issue($invoice, $this->organization, new IssueOptions(file: false));

        $this->assertTrue($outcome->issued, 'the invoice was not issued: '.implode('; ', $outcome->errors));

        return (string) $invoice->fresh()->signed_xml;
    }

    /**
     * ZATCA's content checks passed, no business rule broken and no
     * advisory.
     */
    private function assertAccepted(string $xml, string $what): void
    {
        $this->assertNotSame('', $xml, "{$what} was not signed");

        $result = $this->validate($xml);

        $this->assertTrue($this->sdk()->contentPassed($result), "ZATCA rejected {$what}:\n".implode("\n", $result['errors']));
        $this->assertSame([], array_values(array_filter($result['errors'], static fn (string $error): bool => str_starts_with($error, 'BR-'))), "ZATCA rejected {$what}.");
        $this->assertSame([], $result['warnings'], "an advisory fired on {$what}.");
    }
}
