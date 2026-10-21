<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance\SaudiArabia;

use App\Domains\Compliance\Countries\SaudiArabia\Filing\Submitter;
use App\Domains\Compliance\Countries\SaudiArabia\Signing\CredentialStore;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Invoice\Models\InvoiceSchedule;
use App\Domains\Invoice\Services\ScheduleRunner;
use App\Domains\Invoice\Services\Schedules;
use App\Domains\Organization\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Fixtures\AuthorityAnswer;
use Tests\Fixtures\SigningCredentials;
use Tests\Fixtures\ZatcaSdk;
use Tests\TestCase;

/**
 * Invoices a schedule makes, judged by ZATCA's validator.
 *
 * A schedule's run drafts its invoice through the same rules and drafter as
 * the API; these sign what a run drafted and put it in front of the SDK. A
 * recurring invoice states its period as its supply date (KSA-5) and supply
 * end date (KSA-24), BR-KSA-35 and BR-KSA-36 holding.
 *
 * Set ZATCA_SDK_PATH to run these; they skip without it.
 */
class ScheduleConformanceTest extends TestCase
{
    use RefreshDatabase;
    use SigningCredentials;
    use ZatcaSdk;

    private Organization $organization;

    private bool $signedByAuthority = false;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Storage::fake('creds');
        config(['fatoora.signing.disk' => 'creds']);
        $this->travelTo(CarbonImmutable::now('UTC')->startOfDay()->setTime(9, 0));

        $this->organization = Organization::create([
            'name' => 'Acme Trading', 'name_ar' => 'شركة أكمي للتجارة', 'country' => 'SA', 'vat_number' => '399999999900003',
            'street' => 'King Fahd Road', 'building_number' => '1234', 'district' => 'Al Olaya', 'city' => 'Riyadh', 'postal_code' => '12345',
            'cr_number' => '1010101010', 'compliance_profile' => ['zatca_onboarded' => true],
        ]);

        $authority = $this->authorityCredentials($this->sdk()->path());
        $this->signedByAuthority = $authority !== null;
        $credentials = $authority ?? $this->selfSignedCredentials();

        app(CredentialStore::class)->put($this->organization->id, null, CredentialStore::PCSID, [
            'privateKey' => $credentials['privateKey'], 'pcsid' => $credentials['certificate'], 'secret' => 'device-secret',
        ]);
    }

    /** A monthly service to a business: the month it bills as its supply period. */
    public function test_a_recurring_standard_invoice_validates(): void
    {
        $this->requireSdk();
        $start = CarbonImmutable::now('UTC')->startOfDay();

        $xml = $this->signedRun($this->schedule('monthly', $start->toDateString()));

        $this->assertMatchesRegularExpression('#<cbc:ActualDeliveryDate[^>]*>'.$start->toDateString().'</cbc:ActualDeliveryDate>#', $xml);
        $this->assertMatchesRegularExpression('#<cbc:LatestDeliveryDate[^>]*>'.$start->addMonthNoOverflow()->subDay()->toDateString().'</cbc:LatestDeliveryDate>#', $xml);
        $this->assertAccepted($xml);
    }

    /** A school's term to a parent, a simplified invoice: the term as its period. */
    public function test_a_termly_simplified_invoice_validates(): void
    {
        $this->requireSdk();
        $start = CarbonImmutable::now('UTC')->startOfDay();

        $xml = $this->signedRun($this->schedule('termly', null, [
            'type' => 'simplified',
            'buyer_vat_number' => null,
            'buyer_address' => null,
            'buyer_name' => 'Fahad Al-Harbi',
            'buyer_name_ar' => 'فهد الحربي',
            'lines' => [['description' => 'Grade 5 tuition, first term', 'description_ar' => 'رسوم الصف الخامس، الفصل الأول', 'quantity' => 1, 'unit_price' => '9000.00']],
        ], [['start_date' => $start->toDateString(), 'end_date' => $start->addDays(100)->toDateString()]]));

        $this->assertMatchesRegularExpression('#<cbc:LatestDeliveryDate[^>]*>'.$start->addDays(100)->toDateString().'</cbc:LatestDeliveryDate>#', $xml);
        $this->assertAccepted($xml);
    }

    /** A service already delivered, paid in three: the first part, a tax invoice for its share. */
    public function test_an_instalment_invoice_validates(): void
    {
        $this->requireSdk();

        $xml = $this->signedRun($this->schedule('monthly', CarbonImmutable::now('UTC')->toDateString(), more: ['occurrences' => 3, 'instalment_model' => 'periodic']));

        $this->assertMatchesRegularExpression('#<cbc:InvoiceTypeCode[^>]*>388</cbc:InvoiceTypeCode>#', $xml);
        $this->assertMatchesRegularExpression('#<cbc:TaxExclusiveAmount[^>]*>333.33</cbc:TaxExclusiveAmount>#', $xml);
        $this->assertAccepted($xml);
    }

    /**
     * A sale delivered up front and paid in three: one tax invoice for the
     * whole on delivery, its supply date the delivery's and no period.
     */
    public function test_a_delivered_plan_invoice_validates(): void
    {
        $this->requireSdk();

        $xml = $this->signedRun($this->schedule('monthly', CarbonImmutable::now('UTC')->toDateString(), more: ['occurrences' => 3, 'instalment_model' => 'delivered']));

        $this->assertMatchesRegularExpression('#<cbc:TaxExclusiveAmount[^>]*>1000.00</cbc:TaxExclusiveAmount>#', $xml);
        $this->assertStringNotContainsString('LatestDeliveryDate', $xml);
        $this->assertAccepted($xml);
    }

    /** School fees paid ahead: an instalment before the last is a prepayment invoice (386). */
    public function test_an_advance_instalment_validates(): void
    {
        $this->requireSdk();

        $xml = $this->signedRun($this->schedule('monthly', CarbonImmutable::now('UTC')->toDateString(), more: ['occurrences' => 3, 'instalment_model' => 'prepayment']));

        $this->assertMatchesRegularExpression('#<cbc:InvoiceTypeCode[^>]*>386</cbc:InvoiceTypeCode>#', $xml);
        $this->assertAccepted($xml);
    }

    /**
     * The plan's last instalment: the final invoice for the whole year, the
     * two advances taken off it by number, date and time (KSA-26 to KSA-30)
     * and by VAT category (KSA-31 to KSA-34), the prepaid amount (BT-113)
     * their sum with VAT - many advances to one final invoice
     * (E-Invoicing Detailed Guidelines 8.1.h, p43).
     */
    public function test_the_final_instalment_settles_and_validates(): void
    {
        $this->requireSdk();
        $start = CarbonImmutable::now('UTC')->startOfDay()->subMonthsNoOverflow(2);
        $schedule = $this->schedule('monthly', $start->toDateString(), [
            'lines' => [['description' => 'Annual tuition', 'description_ar' => 'الرسوم الدراسية السنوية', 'quantity' => 1, 'unit_price' => '30000.00']],
        ], more: ['occurrences' => 3, 'instalment_model' => 'prepayment']);

        // The advances are drafted; the final waits for them to be issued.
        app(ScheduleRunner::class)->runSchedule($schedule);
        $advances = Invoice::withoutTenantScope(fn () => Invoice::query()->where('document_type', 'prepayment')->orderBy('supply_date')->get());
        $this->assertCount(2, $advances);

        // Issued outside the device's chain, so the final invoice is the
        // first in it, as the SDK's PIH check expects.
        foreach ($advances as $index => $advance) {
            $number = 'ADV-'.($index + 1);
            AuthorityAnswer::record($advance, 'accepted', ['invoice_number' => $number, 'hash' => base64_encode(hash('sha256', $number, true)), 'issued_at' => $start->addMonthsNoOverflow($index)->setTime(9, 0)]);
        }

        $this->travel(16)->minutes();
        app(ScheduleRunner::class)->retryDue();

        $final = Invoice::withoutTenantScope(fn () => Invoice::with('lines')->where('document_type', 'invoice')->sole());
        $xml = app(Submitter::class)->generate($final, $this->organization)['signed_xml'];

        // 30000 + 4500, less two advances of 10000 + 1500.
        $this->assertMatchesRegularExpression('#<cbc:PrepaidAmount[^>]*>23000.00</cbc:PrepaidAmount>#', $xml);
        $this->assertMatchesRegularExpression('#<cbc:PayableAmount[^>]*>11500.00</cbc:PayableAmount>#', $xml);
        $this->assertSame(2, preg_match_all('#<cbc:DocumentTypeCode[^>]*>386</cbc:DocumentTypeCode>#', $xml));
        $this->assertAccepted($xml);
    }

    /**
     * @param  array<string, mixed>  $invoice
     * @param  list<array{start_date: string, end_date: string}>|null  $terms
     * @param  array<string, mixed>  $more
     */
    private function schedule(string $frequency, ?string $start, array $invoice = [], ?array $terms = null, array $more = []): InvoiceSchedule
    {
        return app(Schedules::class)->create($this->organization, [
            'name' => 'Conformance', 'frequency' => $frequency, 'start_date' => $start, 'terms' => $terms, ...$more,
        ], [
            'type' => 'standard',
            'buyer_name' => 'Beta Industries',
            'buyer_name_ar' => 'شركة بيتا للصناعات',
            'buyer_vat_number' => '399999999800003',
            'buyer_address' => ['street' => 'Olaya Street', 'building_number' => '4321', 'district' => 'Al Murooj', 'city' => 'Riyadh', 'postal_code' => '11564', 'country_code' => 'SA'],
            'lines' => [['description' => 'Monthly maintenance', 'description_ar' => 'صيانة شهرية', 'quantity' => 1, 'unit_price' => '1000.00']],
            ...$invoice,
        ]);
    }

    /** Run the schedule, then sign the invoice its run drafted. */
    private function signedRun(InvoiceSchedule $schedule): string
    {
        app(ScheduleRunner::class)->runSchedule($schedule);

        $invoice = Invoice::withoutTenantScope(fn () => Invoice::with('lines')->latest('created_at')->firstOrFail());
        $signed = app(Submitter::class)->generate($invoice, $this->organization)['signed_xml'];

        $this->assertNotEmpty($signed, 'the invoice was not signed');

        return $signed;
    }

    /**
     * No business rule broken and no advisory, the signature and chain
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
}
