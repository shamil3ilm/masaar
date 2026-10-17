<?php

declare(strict_types=1);

namespace Tests\Feature\Invoice;

use App\Domains\Compliance\Countries\SaudiArabia\Signing\CredentialStore;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Invoice\Models\InvoiceLine;
use App\Domains\Invoice\Services\InvoicePdf;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Tests\Fixtures\SigningCredentials;
use Tests\TestCase;

/**
 * The invoice PDF is PDF/A-3b, as ZATCA's Security Features standard (2.2.1
 * items 10 and 11) asks of a PDF invoice carrying its XML, and the stamp
 * appended to it keeps it so.
 *
 * Checked by an independent PDF/A validator rather than by reading back what
 * the renderer was told to write. Set PDFA_VALIDATOR_PATH to its command-line
 * launcher to run these; they skip without it.
 */
class PdfaConformanceTest extends TestCase
{
    use RefreshDatabase;
    use SigningCredentials;

    private Organization $acme;

    protected function setUp(): void
    {
        parent::setUp();

        if (! config('fatoora.validation.pdfa_validator')) {
            $this->markTestSkipped('PDFA_VALIDATOR_PATH is not set.');
        }

        Storage::fake('creds');
        config(['fatoora.signing.disk' => 'creds']);

        $this->acme = Organization::create([
            'name' => 'Acme Trading',
            'name_ar' => 'شركة أكمي للتجارة',
            'country' => 'SA',
            'vat_number' => '300000000000003',
            'street' => 'King Fahd Road',
            'building_number' => '1234',
            'district' => 'Olaya',
            'city' => 'Riyadh',
            'postal_code' => '12345',
        ]);
    }

    /**
     * Phase one: no device certificate, so no stamp.
     */
    public function test_the_unstamped_pdf_is_pdfa(): void
    {
        $this->assertConforms(app(InvoicePdf::class)->render($this->invoice(), $this->acme));
    }

    /**
     * The stamp is an incremental update; it must not break conformance.
     */
    public function test_the_stamped_pdf_is_pdfa(): void
    {
        $credentials = $this->selfSignedCredentials(0x1234567890);
        app(CredentialStore::class)->put($this->acme->id, null, CredentialStore::PCSID, [
            'privateKey' => $credentials['privateKey'],
            'pcsid' => $credentials['certificate'],
            'secret' => 'secret',
        ]);

        $pdf = app(InvoicePdf::class)->render($this->invoice(), $this->acme);

        $this->assertStringContainsString('/ETSI.CAdES.detached', $pdf);
        $this->assertConforms($pdf);
    }

    private function assertConforms(string $pdf): void
    {
        $file = tempnam(sys_get_temp_dir(), 'pdfa').'.pdf';
        file_put_contents($file, $pdf);

        try {
            $result = Process::timeout(180)->run([
                (string) config('fatoora.validation.pdfa_validator'),
                '--flavour', '3b',
                '--format', 'text',
                '--verbose',
                $file,
            ]);

            // The text report reads "PASS <file>" or "FAIL <file>" and then
            // lists the failed rules, which is what a failure should show.
            $this->assertStringStartsWith('PASS', ltrim($result->output()), $result->output().$result->errorOutput());
        } finally {
            @unlink($file);
        }
    }

    private function invoice(): Invoice
    {
        $invoice = Invoice::withoutTenantScope(fn () => Invoice::query()->create([
            'org_id' => $this->acme->id,
            'invoice_number' => 'ACME-1',
            'type' => 'simplified',
            'document_type' => 'invoice',
            'status' => 'draft',
            'issue_date' => now()->toDateString(),
            'currency' => 'SAR',
            'buyer_name' => 'Walk-in customer',
            'subtotal' => '100.00',
            'tax_amount' => '15.00',
            'total' => '115.00',
        ]));

        InvoiceLine::query()->create([
            'invoice_id' => $invoice->id,
            'description' => 'Consulting hour',
            'description_ar' => 'ساعة استشارية',
            'quantity' => '1',
            'unit_code' => 'HUR',
            'unit_price' => '100.00',
            'tax_rate' => '15',
            'tax_amount' => '15.00',
            'line_total' => '115.00',
        ]);

        $invoice->forceFill([
            'status' => 'issued',
            'icv' => 1,
            'hash' => base64_encode(hash('sha256', 'acme', true)),
            'qr_code' => base64_encode('AQVTZWxsZXI='),
            'signed_xml' => '<?xml version="1.0" encoding="UTF-8"?><Invoice>signed</Invoice>',
        ])->save();

        return $invoice->fresh(['lines']);
    }
}
