<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance\SaudiArabia;

use App\Domains\Auth\Models\User;
use App\Domains\Compliance\Countries\SaudiArabia\Signing\CredentialStore;
use App\Domains\Compliance\Engine\DTOs\IssueOptions;
use App\Domains\Compliance\Engine\Services\ComplianceEngine;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Invoice\Services\InvoiceAdjustments;
use App\Domains\Invoice\Services\InvoiceDrafter;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\SigningCredentials;
use Tests\Fixtures\ZatcaSdk;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * The documents a return, an exchange or a correction makes
 * (InvoiceAdjustments), judged by ZATCA's validator: each note names the
 * original (BR-KSA-56) with its KSA-10 reason (BR-KSA-17), of the
 * original's type, and each new invoice is an invoice like any other.
 *
 * Set ZATCA_SDK_PATH to run these; they skip without it.
 */
class AdjustmentConformanceTest extends TestCase
{
    use RefreshDatabase;
    use SigningCredentials;
    use ZatcaSdk;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('creds');
        config(['fatoora.signing.disk' => 'creds']);

        $this->organization = Organization::create([
            'name' => 'Riyadh Office Furniture', 'country' => 'SA', 'vat_number' => '399999999900003',
            'street' => 'King Fahd Road', 'building_number' => '1234', 'district' => 'Al Olaya', 'city' => 'Riyadh',
            'postal_code' => '12345', 'cr_number' => '1010101010', 'compliance_profile' => ['zatca_onboarded' => true],
        ]);
    }

    /**
     * @return iterable<string, array{string, array<string, mixed>, list<string>}>
     */
    public static function adjustments(): iterable
    {
        yield 'standard, partial return' => ['standard', ['kind' => 'partial_return', 'lines' => [['line' => 1, 'quantity' => 2]]], ['381']];
        yield 'standard, discount on damage' => ['standard', ['kind' => 'discount', 'discount_percent' => 10, 'lines' => [['line' => 2]]], ['381']];
        yield 'standard, dearer replacement' => ['standard', ['kind' => 'price_difference', 'lines' => [['line' => 1, 'quantity' => 1]],
            'items' => [['description' => 'Executive chair', 'description_ar' => 'كرسي تنفيذي', 'quantity' => 1, 'unit_price' => 400]]], ['383']];
        yield 'standard, exchange' => ['standard', ['kind' => 'exchange', 'lines' => [['line' => 1, 'quantity' => 1]],
            'items' => [['description' => 'Executive chair', 'description_ar' => 'كرسي تنفيذي', 'quantity' => 1, 'unit_price' => 400]]], ['381', '388']];
        yield 'standard, buyer corrected' => ['standard', ['kind' => 'buyer_correction', 'buyer' => ['buyer_name' => 'Gulf Supplies Trading Co']], ['381', '388']];
        yield 'simplified, full return' => ['simplified', ['kind' => 'return'], ['381']];
    }

    /**
     * @param  array<string, mixed>  $request
     * @param  list<string>  $codes
     */
    #[DataProvider('adjustments')]
    public function test_adjustment_documents_validate(string $type, array $request, array $codes): void
    {
        $original = $this->accepted($type);
        $plan = app(InvoiceAdjustments::class)->plan($this->organization, $original, $request);

        $this->assertSame($codes, array_map(fn (array $document): string => match ($document['type']) {
            'credit_note' => '381', 'debit_note' => '383', default => '388',
        }, $plan->documents));

        foreach ($plan->documents as $document) {
            $invoice = app(InvoiceDrafter::class)->draft($document['draft'], (string) $this->organization->id, null, $document['countryFields']);
            $outcome = app(ComplianceEngine::class)->issue($invoice, $this->organization, new IssueOptions(file: false));
            $this->assertTrue($outcome->issued, implode("\n", $outcome->errors));

            $xml = (string) $invoice->fresh()->signed_xml;
            $name = $type === 'standard' ? '0100000' : '0200000';
            $this->assertMatchesRegularExpression('#<cbc:InvoiceTypeCode name="'.$name.'">\d{3}</cbc:InvoiceTypeCode>#', $xml);

            if ($document['role'] !== InvoiceAdjustments::INVOICE) {
                $this->assertMatchesRegularExpression('#<cac:BillingReference>.*?<cbc:ID[^>]*>'.preg_quote((string) $original->invoice_number, '#').'</cbc:ID>#s', $xml);
            }

            $result = $this->validate($xml);
            $this->assertTrue($this->sdk()->contentPassed($result), "ZATCA rejected the {$document['role']} document:\n".implode("\n", $result['errors']));
            $this->assertSame([], array_values(array_filter($result['errors'], static fn (string $error): bool => str_starts_with($error, 'BR-'))));
        }
    }

    /**
     * 4 chairs at 250 and a desk at 2,000, VAT 15%, issued through the API
     * and accepted, as ZATCA would accept it.
     */
    private function accepted(string $type): Invoice
    {
        $this->requireSdk();

        $credentials = $this->authorityCredentials($this->sdk()->path()) ?? $this->selfSignedCredentials();
        app(CredentialStore::class)->put($this->organization->id, null, CredentialStore::PCSID, [
            'privateKey' => $credentials['privateKey'], 'pcsid' => $credentials['certificate'], 'secret' => 'device-secret',
        ]);

        $user = User::factory()->create();
        $user->organizations()->attach($this->organization->id, ['role' => 'admin', 'status' => 'active']);
        $token = JWTAuth::claims(['org_id' => $this->organization->id, 'role' => 'admin'])->fromUser($user);

        $response = $this->withToken($token)->postJson('/api/invoices', [
            'type' => $type, 'issue_date' => now()->toDateString(), 'supply_date' => now()->toDateString(),
            'buyer_name' => 'Gulf Supplies', 'buyer_name_ar' => 'الخليج للتوريدات',
            ...($type === 'standard' ? ['buyer_vat_number' => '399999999800003', 'buyer_address' => [
                'street' => 'Olaya Street', 'building_number' => '4321', 'district' => 'Al Murooj', 'city' => 'Riyadh', 'postal_code' => '11564', 'country_code' => 'SA',
            ]] : []),
            'lines' => [
                ['description' => 'Office chair', 'description_ar' => 'كرسي مكتب', 'quantity' => 4, 'unit_price' => '250.00'],
                ['description' => 'Desk', 'description_ar' => 'مكتب', 'quantity' => 1, 'unit_price' => '2000.00'],
            ],
        ]);
        $this->assertSame(201, $response->status(), (string) $response->getContent());

        $invoice = Invoice::withoutTenantScope(fn () => Invoice::query()->findOrFail($response->json('data.invoice.id')));
        $this->assertTrue(app(ComplianceEngine::class)->issue($invoice, $this->organization, new IssueOptions(file: false))->issued);

        // A note waits for ZATCA to accept its original (VatPeriodTracker);
        // here it has, as it would.
        Invoice::withoutTenantScope(fn () => Invoice::query()->whereKey($invoice->id)->update(['status' => 'accepted']));

        return Invoice::withoutTenantScope(fn () => Invoice::query()->with('lines')->findOrFail($invoice->id));
    }
}
