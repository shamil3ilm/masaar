<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance\SaudiArabia;

use App\Domains\Billing\DTOs\ReceivableDraft;
use App\Domains\Billing\DTOs\ReceivedPayment;
use App\Domains\Billing\Models\Receivable;
use App\Domains\Billing\Models\Refund;
use App\Domains\Billing\Services\Payments;
use App\Domains\Billing\Services\Receivables;
use App\Domains\Billing\Services\Refunds;
use App\Domains\Billing\Settings\FinanceSettings;
use App\Domains\Compliance\Countries\SaudiArabia\Filing\OfflineFallback;
use App\Domains\Compliance\Countries\SaudiArabia\Signing\CredentialStore;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Fixtures\SigningCredentials;
use Tests\Fixtures\ZatcaSdk;
use Tests\TestCase;

/**
 * The platform's own invoices to its customers, judged by ZATCA's validator.
 *
 * The billing organization issues through the Saudi module like any seller
 * (PlatformInvoices). A customer invoiced in dirhams gets a dirham invoice
 * whose VAT is stated in riyals too: TaxCurrencyCode (BT-6) is SAR
 * (BR-KSA-68, BR-KSA-EN16931-02) and the second TaxTotal carries the VAT in
 * SAR (BT-111, BR-53) at the recorded rate. A refund is a credit note
 * naming the invoice (BT-25) and saying why (KSA-10).
 *
 * Filing is stubbed: what is judged is the signed document. Set
 * ZATCA_SDK_PATH to run these; they skip without it.
 */
class BillingConformanceTest extends TestCase
{
    use RefreshDatabase;
    use SigningCredentials;
    use ZatcaSdk;

    private Organization $platform;

    private Organization $gulf;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Storage::fake('creds');
        config(['fatoora.signing.disk' => 'creds']);
        $this->travelTo(CarbonImmutable::now('UTC')->startOfDay()->setTime(9, 0));

        $this->platform = Organization::create([
            'name' => 'Acme Platform', 'name_ar' => 'منصة أكمي', 'country' => 'SA', 'vat_number' => '399999999900003',
            'street' => 'King Fahd Road', 'building_number' => '1234', 'district' => 'Al Olaya', 'city' => 'Riyadh', 'postal_code' => '12345',
            'cr_number' => '1010101010', 'compliance_profile' => ['zatca_onboarded' => true],
        ]);
        config(['billing.organization_id' => $this->platform->id]);

        $this->gulf = Organization::create([
            'name' => 'Gulf Trading LLC', 'name_ar' => 'الخليج للتجارة ذ.م.م', 'country' => 'AE', 'vat_number' => '100398517200003',
            'street' => 'Sheikh Zayed Road', 'building_number' => '12', 'city' => 'Dubai', 'postal_code' => '00000',
        ]);

        $settings = app(FinanceSettings::class);
        $settings->setCurrencies(['SAR', 'AED'], 'SAR', null);
        $settings->set(FinanceSettings::CURRENCY, 'AED', null, (string) $this->gulf->id);

        $this->mock(OfflineFallback::class, fn ($mock) => $mock->shouldReceive('submit')->andReturn(['success' => true, 'submission_id' => null, 'state' => 'stubbed', 'errors' => [], 'warnings' => []]));

        $credentials = $this->authorityCredentials($this->sdk()->path()) ?? $this->selfSignedCredentials();

        app(CredentialStore::class)->put($this->platform->id, null, CredentialStore::PCSID, [
            'privateKey' => $credentials['privateKey'], 'pcsid' => $credentials['certificate'], 'secret' => 'device-secret',
        ]);
    }

    /**
     * 1000 AED at 1.0210: 150 AED of VAT, 153.15 SAR, both stated.
     */
    public function test_a_dirham_invoice_states_riyal_vat(): void
    {
        $this->requireSdk();

        $receivable = $this->issue();
        $xml = $this->signed((string) $receivable->invoice_id);

        $this->assertMatchesRegularExpression('#<cbc:DocumentCurrencyCode[^>]*>AED</cbc:DocumentCurrencyCode>#', $xml);
        $this->assertMatchesRegularExpression('#<cbc:TaxCurrencyCode[^>]*>SAR</cbc:TaxCurrencyCode>#', $xml);
        $this->assertMatchesRegularExpression('#<cbc:TaxAmount[^>]*currencyID="AED"[^>]*>150.00</cbc:TaxAmount>#', $xml);
        $this->assertMatchesRegularExpression('#<cbc:TaxAmount[^>]*currencyID="SAR"[^>]*>153.15</cbc:TaxAmount>#', $xml);
        $this->assertSame('153.15', (string) $receivable->vat_amount_tax);
        $this->assertAccepted($xml);
    }

    /**
     * Paid, then 200 AED credited back through a credit note against it,
     * the rest kept as the customer's credit.
     */
    public function test_a_refund_credit_note_validates(): void
    {
        $this->requireSdk();

        $receivable = $this->issue();
        // The refund's credit note waits for the invoice to be accepted by
        // ZATCA (VatPeriodTracker); here it is, as it would be.
        Invoice::withoutTenantScope(fn () => Invoice::query()->whereKey($receivable->invoice_id)->update(['status' => 'accepted']));
        app(Payments::class)->recordTransfer($this->gulf, new ReceivedPayment('1150', 'AED', CarbonImmutable::now(), 'TRF-1', exchangeRate: '1.021'), null);
        $this->travel(5)->minutes();

        $refund = app(Refunds::class)->refund($receivable, '200', 'Service not delivered for six days', 'لم تُقدَّم الخدمة لستة أيام', Refund::TO_CREDIT, null, null);
        $xml = $this->signed((string) $refund->credit_note_id);

        $this->assertMatchesRegularExpression('#<cbc:InvoiceTypeCode name="[0-9]+">381</cbc:InvoiceTypeCode>#', $xml);
        $this->assertMatchesRegularExpression('#<cac:BillingReference>.*?<cbc:ID[^>]*>'.preg_quote((string) Invoice::withoutTenantScope(fn () => Invoice::query()->whereKey($receivable->invoice_id)->value('invoice_number')), '#').'</cbc:ID>#s', $xml);
        $this->assertMatchesRegularExpression('#<cbc:TaxAmount[^>]*currencyID="SAR"[^>]*>30.63</cbc:TaxAmount>#', $xml);
        $this->assertSame('230.00', (string) $refund->credited_amount);
        // KSA-13: the note names the invoice before it in the platform's chain.
        $this->assertStringContainsString((string) Invoice::withoutTenantScope(fn () => Invoice::query()->whereKey($receivable->invoice_id)->value('hash')), $xml);
        $this->assertAccepted($xml);
    }

    private function issue(): Receivable
    {
        return app(Receivables::class)->issue($this->gulf, new ReceivableDraft(
            description: 'E-invoicing subscription, one month',
            descriptionAr: 'اشتراك الفوترة الإلكترونية لمدة شهر',
            amount: '1000',
            periodStart: CarbonImmutable::now('UTC')->startOfDay(),
            periodEnd: CarbonImmutable::now('UTC')->startOfDay()->addMonthNoOverflow()->subDay(),
            exchangeRate: '1.021',
        ), null);
    }

    private function signed(string $invoiceId): string
    {
        $xml = (string) Invoice::withoutTenantScope(fn () => Invoice::query()->whereKey($invoiceId)->value('signed_xml'));
        $this->assertNotSame('', $xml, 'the document was not signed');

        return $xml;
    }

    /**
     * ZATCA's content checks passed, no business rule broken and no
     * advisory. The SDK's PIH stage compares against a file of its own, so
     * a document after the first in the chain is read for its predecessor
     * instead (as InsuranceSplitConformanceTest and TillConformanceTest do).
     */
    private function assertAccepted(string $xml): void
    {
        $result = $this->validate($xml);

        $this->assertTrue($this->sdk()->contentPassed($result), "ZATCA rejected the document:\n".implode("\n", $result['errors']));
        $this->assertSame([], array_values(array_filter($result['errors'], static fn (string $error): bool => str_starts_with($error, 'BR-'))), 'ZATCA rejected the document.');
        $this->assertSame([], $result['warnings'], 'an advisory fired.');
    }
}
