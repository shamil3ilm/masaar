<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Domains\Auth\Models\User;
use App\Domains\Compliance\Fatoora\DTOs\AddressData;
use App\Domains\Compliance\Fatoora\Services\ComplianceSampleSet;
use App\Domains\Compliance\Fatoora\Services\DocumentBuilder;
use App\Domains\Compliance\Fatoora\Services\VatPeriodTracker;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\SigningCredentials;
use Tests\TestCase;

/**
 * Which clock a ZATCA value is read off.
 *
 * The application stores instants in UTC, and nearly everything that reads
 * one back wants it that way: a retry schedule, a token expiry, the gap
 * between two events. A handful of values are not instants. They are civil
 * statements - the date and time a document says it was issued, the day a
 * filing deadline falls on - and those are the Kingdom's, because that is the
 * clock the taxpayer, the consumer scanning a QR and the authority keep.
 *
 * Reading a civil statement off UTC does not make it three hours early. For
 * the three hours after midnight in Riyadh it names the previous day, so a
 * document issued at 01:30 said 22:30 and the two were twenty-one hours
 * apart. Most tests here freeze the clock inside that window, because it is
 * the only window in which the two clocks disagree and so the only one in
 * which a wrong answer is visible at all.
 */
class SaudiClockTest extends TestCase
{
    use RefreshDatabase;
    use SigningCredentials;

    private const CBC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2';

    /**
     * 22:30 UTC is 01:30 the next day in Riyadh - two clocks, two dates.
     */
    private const UTC_INSTANT = '2026-10-09 22:30:00';

    private const SAUDI_DATE = '2026-10-10';

    private const SAUDI_TIME = '01:30:00';

    private Organization $organization;

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
    }

    /**
     * IssueTime is a wall-clock time in the Kingdom, and it sits beside an
     * IssueDate that is already the business date. Taking the time off the
     * application's clock and the date off the invoice put the two on
     * different clocks in the same header.
     */
    public function test_issue_time_is_the_kingdoms(): void
    {
        $xpath = $this->xpath($this->build()['xml']);

        $this->assertSame(
            self::SAUDI_DATE,
            $xpath->query('/*/cbc:IssueDate')->item(0)->textContent,
        );

        $this->assertSame(
            self::SAUDI_TIME,
            $xpath->query('/*/cbc:IssueTime')->item(0)->textContent,
            'IssueTime was read off the application clock, not the Kingdom one.',
        );
    }

    /**
     * ZATCA compares the QR's third tag against the header it sits in, so the
     * three statements a document makes about when it was issued have to be
     * one statement.
     */
    public function test_the_qr_agrees_with_the_header(): void
    {
        $built = $this->build();
        $xpath = $this->xpath($built['xml']);

        $this->assertSame(
            $xpath->query('/*/cbc:IssueDate')->item(0)->textContent
                .'T'.$xpath->query('/*/cbc:IssueTime')->item(0)->textContent,
            $this->qrTag($built['qr_code'], 3),
            'The QR states a different moment than the header it is in.',
        );
    }

    /**
     * The six documents sent to the compliance endpoint have to be samples of
     * what this platform really sends. Once a real invoice states Riyadh time,
     * a sample stating UTC is a sample of nothing.
     */
    public function test_onboarding_samples_use_saudi_time(): void
    {
        $this->travelTo(self::UTC_INSTANT);

        $samples = app(ComplianceSampleSet::class)->build(
            sellerName: 'Acme Trading',
            sellerVatNumber: '300000000000003',
            sellerCrNumber: '1010101010',
            sellerAddress: new AddressData(
                street: 'King Fahd Road',
                buildingNumber: '1234',
                district: 'Al Olaya',
                city: 'Riyadh',
                postalCode: '12345',
            ),
            numberPrefix: 'COMP',
        );

        $this->assertNotEmpty($samples);

        foreach ($samples as $key => $sample) {
            $this->assertSame(self::SAUDI_DATE, $sample['data']->issueDate, $key);
            $this->assertSame(self::SAUDI_TIME, $sample['data']->issueTime, $key);

            if ($sample['data']->supplyDate !== null) {
                $this->assertSame(self::SAUDI_DATE, $sample['data']->supplyDate, $key);
            }
        }
    }

    /**
     * A deadline of the twenty-eighth includes the twenty-eighth.
     *
     * The period closed at the start of the deadline day rather than the end
     * of it, so a credit note raised on the deadline day was told the original
     * period had closed and belonged in the current one instead.
     */
    public function test_the_deadline_day_is_open(): void
    {
        // 10:00 UTC is 13:00 in Riyadh: the deadline day on either clock, so
        // what this pins is the day being included, not the timezone.
        $this->travelTo('2026-10-28 10:00:00');

        $this->assertTrue(
            app(VatPeriodTracker::class)->isPeriodOpen('2026-09-15'),
            'A period read as closed on its own filing deadline.',
        );
    }

    /**
     * And the day after it does not, on the Kingdom's calendar - which in the
     * first hours of a Riyadh morning is a day ahead of the application's.
     */
    public function test_the_day_after_is_closed(): void
    {
        $this->travelTo('2026-10-28 21:30:00');

        $this->assertFalse(
            app(VatPeriodTracker::class)->isPeriodOpen('2026-09-15'),
            'The Kingdom had passed the deadline; the period still read open.',
        );
    }

    /**
     * An invoice built with the clock frozen inside the window where the two
     * clocks name different days.
     *
     * @return array{xml: string, qr_code: string}
     */
    private function build(): array
    {
        $this->travelTo(self::UTC_INSTANT);

        // Signed in after the clock moves, not before. A token minted under
        // the real clock and then carried into the frozen instant is read as
        // expired, and the invoice never gets built.
        $token = $this->postJson('/api/auth/login', [
            'email' => 'biller@masaar.test',
            'password' => 'password',
        ])->json('data.token.access_token');

        $id = $this->withToken($token)
            ->postJson('/api/invoices', [
                'invoice_number' => 'INV-'.uniqid(),
                'type' => 'simplified',
                // The date a seller in Riyadh would write, which is the date
                // the Kingdom is on and not the one UTC is on.
                'issue_date' => self::SAUDI_DATE,
                'buyer_name' => 'Buyer Co',
                'lines' => [[
                    'description' => 'Item',
                    'quantity' => 1,
                    'unit_price' => '1000.00',
                    'tax_rate' => '15',
                    'tax_category' => 'S',
                ]],
            ])
            ->assertSuccessful()
            ->json('data.invoice.id');

        $invoice = Invoice::withoutTenantScope(fn () => Invoice::with('lines')->findOrFail($id));

        $this->assertSame(
            self::UTC_INSTANT,
            $invoice->created_at->utc()->format('Y-m-d H:i:s'),
            'The frozen clock never reached the stored timestamp, so nothing below means anything.',
        );

        $credentials = $this->selfSignedCredentials();

        $built = app(DocumentBuilder::class)->generateComplianceData(
            invoice: $invoice,
            organization: $this->organization,
            previousInvoiceHash: null,
            privateKey: $credentials['privateKey'],
            certificate: $credentials['certificate'],
        );

        return ['xml' => $built['xml'], 'qr_code' => $built['qr_code']];
    }

    /**
     * One tag out of the QR's TLV, by number.
     */
    private function qrTag(string $base64, int $wanted): string
    {
        $bytes = base64_decode($base64, true);
        $this->assertNotFalse($bytes, 'The QR is not base64.');

        $offset = 0;

        while ($offset + 1 < strlen($bytes)) {
            $tag = ord($bytes[$offset]);
            $length = ord($bytes[$offset + 1]);
            $value = substr($bytes, $offset + 2, $length);

            if ($tag === $wanted) {
                return $value;
            }

            $offset += 2 + $length;
        }

        $this->fail('The QR carries no tag '.$wanted.'.');
    }

    private function xpath(string $xml): DOMXPath
    {
        $dom = new DOMDocument;
        $dom->loadXML($xml);

        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('cbc', self::CBC);

        return $xpath;
    }
}
