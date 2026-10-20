<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance\SaudiArabia;

use App\Domains\Compliance\Countries\SaudiArabia\Filing\Submitter;
use App\Domains\Compliance\Countries\SaudiArabia\Signing\CredentialStore;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\RecordedOriginals;
use Tests\Fixtures\SigningCredentials;
use Tests\Fixtures\ZatcaSdk;
use Tests\TestCase;

/**
 * A sale made while the till was offline, issued when it reconnects, judged
 * by ZATCA's validator.
 *
 * As ZATCA's Detailed Guidelines for E-Invoicing require, the document is
 * dated when the platform generates it - "Invoice Issue Date must be the date
 * on which final Tax Invoice is generated" (p60) - so IssueDate, IssueTime
 * and the QR's timestamp are now, on the Kingdom's clock, and the
 * twenty-four hours to report it run from now (p12, p18, p32). The sale is
 * the supply: its date is the supply date (KSA-5, cac:Delivery/
 * cbc:ActualDeliveryDate), which the data dictionary allows on every
 * document type and no rule ties to the issue date. The sale here was made
 * the previous afternoon in Riyadh - and three days before, which is late
 * and is still issued.
 *
 * Set ZATCA_SDK_PATH to run these; they skip without it, as
 * ZatcaConformanceTest does.
 */
class SaleTimeConformanceTest extends TestCase
{
    use RecordedOriginals;
    use RefreshDatabase;
    use SigningCredentials;
    use ZatcaSdk;

    private const CBC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2';

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

        app(CredentialStore::class)->put($this->organization->id, null, CredentialStore::PCSID, [
            'privateKey' => $credentials['privateKey'],
            'pcsid' => $credentials['certificate'],
            'secret' => 'device-secret',
        ]);
    }

    /**
     * @return iterable<string, array{string, string, int}>
     */
    public static function documents(): iterable
    {
        yield 'simplified invoice' => ['simplified', 'invoice', 1];
        yield 'simplified credit note' => ['simplified', 'credit_note', 1];
        yield 'standard invoice' => ['standard', 'invoice', 1];
        yield 'simplified invoice, three days late' => ['simplified', 'invoice', 3];
    }

    #[DataProvider('documents')]
    public function test_a_late_issue_validates(string $type, string $document, int $daysLate): void
    {
        // An afternoon in Riyadh, a day or more before now.
        $sold = Carbon::now('Asia/Riyadh')->subDays($daysLate)->setTime(15, 4, 5)->utc();
        $generated = Carbon::now('Asia/Riyadh')->startOfSecond();

        [$xml, $qr] = $this->issued($type, $document, $sold);
        $result = $this->validate($xml);

        $this->assertSame([], $this->businessRules($result['errors']), "ZATCA rejected the late {$type} {$document}.");
        $this->assertSame([], $result['warnings'], "an advisory fired on the late {$type} {$document}.");

        $xpath = $this->xpath($xml);
        $issueDate = $xpath->evaluate('string(/*/cbc:IssueDate)');
        $issueTime = $xpath->evaluate('string(/*/cbc:IssueTime)');
        $issued = Carbon::parse($issueDate.' '.$issueTime, 'Asia/Riyadh');

        $this->assertSame($generated->toDateString(), $issueDate, 'IssueDate is not the day the invoice was generated');
        $this->assertLessThan(60, abs($issued->diffInSeconds($generated)), 'IssueTime is not when the invoice was generated');
        $this->assertSame($issueDate.'T'.$issueTime, $this->qrTag($qr, 3), 'the QR states another moment than the issue');
        $this->assertSame($sold->copy()->setTimezone('Asia/Riyadh')->toDateString(), $xpath->evaluate('string(//cac:Delivery/cbc:ActualDeliveryDate)'), 'the supply date is not the sale\'s');

        $signed = Carbon::parse($xpath->evaluate('string(//*[local-name()="SigningTime"])'), 'Asia/Riyadh');
        $this->assertTrue($signed->greaterThanOrEqualTo($issued), 'the document was signed before it was issued');
    }

    /**
     * @return array{string, string}
     */
    private function issued(string $type, string $document, Carbon $sold): array
    {
        $isNote = $document !== 'invoice';
        $day = $sold->copy()->setTimezone('Asia/Riyadh')->toDateString();

        if ($isNote) {
            $this->recordedOriginal($this->organization, 'INV-ORIGINAL-1', $type);
        }

        $invoice = Invoice::withoutTenantScope(fn () => Invoice::create([
            'org_id' => $this->organization->id,
            'invoice_number' => strtoupper($type.'-'.$document).'-LATE',
            'type' => $type,
            'document_type' => $document,
            'billing_ref' => $isNote ? 'INV-ORIGINAL-1' : null,
            'adjustment_reason' => $isNote ? 'Correction of quantity' : null,
            'status' => 'draft',
            'issue_date' => $day,
            'sold_at' => $sold,
            'supply_date' => $day,
            'currency' => 'SAR',
            'buyer_name' => 'Beta Industries',
            'buyer_vat_number' => $type === 'standard' ? '399999999800003' : null,
            'buyer_address' => $type === 'standard' ? [
                'street' => 'Olaya Street',
                'building_number' => '4321',
                'district' => 'Al Murooj',
                'city' => 'Riyadh',
                'postal_code' => '11564',
                'country_code' => 'SA',
            ] : null,
            'subtotal' => '1000.00',
            'tax_amount' => '150.00',
            'total' => '1150.00',
        ]));

        $invoice->lines()->create([
            'description' => 'Consulting',
            'quantity' => '1.000',
            'unit_price' => '1000.00',
            'tax_rate' => '15.00',
            'tax_category' => 'S',
            'tax_amount' => '150.00',
            'line_total' => '1150.00',
        ]);

        $result = app(Submitter::class)->generate($invoice->fresh(['lines']), $this->organization);

        return [(string) $result['signed_xml'], (string) $result['qr_code']];
    }

    /**
     * As ZatcaConformanceTest reads the validator: with the authority's own
     * certificate, everything but the signatureValue check that fails on
     * ZATCA's own pretty-printed samples; otherwise the business rules.
     *
     * @param  list<string>  $errors
     * @return list<string>
     */
    private function businessRules(array $errors): array
    {
        if ($this->signedByAuthority) {
            return array_values(array_diff($errors, ['signatureValue: wrong signature Value']));
        }

        return array_values(array_filter($errors, static fn (string $error): bool => str_starts_with($error, 'BR-')));
    }

    private function qrTag(string $base64, int $wanted): string
    {
        $bytes = (string) base64_decode($base64, true);

        for ($offset = 0; $offset + 1 < strlen($bytes); $offset += 2 + ord($bytes[$offset + 1])) {
            if (ord($bytes[$offset]) === $wanted) {
                return substr($bytes, $offset + 2, ord($bytes[$offset + 1]));
            }
        }

        $this->fail('The QR carries no tag '.$wanted.'.');
    }

    private function xpath(string $xml): DOMXPath
    {
        $dom = new DOMDocument;
        $dom->loadXML($xml);

        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('cbc', self::CBC);
        $xpath->registerNamespace('cac', 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2');

        return $xpath;
    }
}
