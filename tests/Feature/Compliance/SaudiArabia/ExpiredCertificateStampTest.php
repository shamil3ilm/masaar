<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance\SaudiArabia;

use App\Domains\Compliance\Countries\SaudiArabia\Exceptions\FatooraException;
use App\Domains\Compliance\Countries\SaudiArabia\Filing\Submitter;
use App\Domains\Compliance\Countries\SaudiArabia\Signing\CredentialStore;
use App\Domains\Invoice\Enums\InvoiceStatus;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;
use App\Support\Errors\ErrorCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Fixtures\SigningCredentials;
use Tests\TestCase;

/**
 * An expired certificate does not stamp.
 *
 * The Security Features Implementation Standard has a document stamped
 * only under a valid CSID. Expiry was checked when filing, so a document
 * stamped under an expired certificate took a counter and a place in the
 * chain and could then never be filed.
 */
class ExpiredCertificateStampTest extends TestCase
{
    use RefreshDatabase;
    use SigningCredentials;

    public function test_expired_certificate_spends_no_counter(): void
    {
        Storage::fake('creds');
        config(['fatoora.signing.disk' => 'creds']);

        $acme = Organization::create([
            'name' => 'Acme Trading', 'country' => 'SA', 'vat_number' => '300000000000003', 'cr_number' => '1010101010',
            'street' => 'King Fahd Road', 'building_number' => '1234', 'district' => 'Al Olaya', 'city' => 'Riyadh', 'postal_code' => '12345',
            'compliance_profile' => ['zatca_onboarded' => true],
        ]);
        $keys = $this->selfSignedCredentials(days: 1);
        app(CredentialStore::class)->put($acme->id, null, CredentialStore::PCSID, [
            'privateKey' => $keys['privateKey'], 'pcsid' => $keys['certificate'], 'secret' => 'device-secret',
        ]);

        $this->travel(3)->days();

        $invoice = Invoice::withoutTenantScope(fn () => Invoice::create([
            'org_id' => $acme->id, 'invoice_number' => 'EXP-1', 'type' => 'simplified', 'document_type' => 'invoice',
            'status' => 'draft', 'issue_date' => now()->toDateString(), 'supply_date' => now()->toDateString(), 'currency' => 'SAR',
            'subtotal' => '100.00', 'tax_amount' => '15.00', 'total' => '115.00',
        ]));
        $invoice->lines()->create([
            'description' => 'Widget', 'quantity' => '1.000', 'unit_price' => '100.00',
            'tax_rate' => '15.00', 'tax_amount' => '15.00', 'line_total' => '115.00',
        ]);

        try {
            app(Submitter::class)->generate($invoice->fresh(['lines']), $acme);
            $this->fail('an expired certificate stamped the invoice');
        } catch (FatooraException $e) {
            $this->assertSame(ErrorCode::CERT_EXPIRED, $e->getErrorCode());
        }

        $invoice->refresh();
        $this->assertSame(InvoiceStatus::Draft, $invoice->status);
        $this->assertNull($invoice->icv);
    }
}
