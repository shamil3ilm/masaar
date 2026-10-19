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

    /**
     * @param  array<string, mixed>  $invoice
     * @param  list<array{start_date: string, end_date: string}>|null  $terms
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
