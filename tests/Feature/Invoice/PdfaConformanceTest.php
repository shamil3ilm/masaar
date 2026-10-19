<?php

declare(strict_types=1);

namespace Tests\Feature\Invoice;

use App\Domains\Branding\DTOs\ThemeDefinition;
use App\Domains\Branding\Services\BrandAssets;
use App\Domains\Branding\Services\ThemeService;
use App\Domains\Compliance\Countries\SaudiArabia\Signing\CredentialStore;
use App\Domains\Invoice\Enums\PaperSize;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Invoice\Models\InvoiceLine;
use App\Domains\Invoice\Services\InvoiceExtraQr;
use App\Domains\Invoice\Services\InvoicePdf;
use App\Domains\Invoice\Templates\InvoiceTemplates;
use App\Domains\Invoice\Templates\TemplateLayout;
use App\Domains\Organization\Models\Organization;
use App\Domains\Organization\Services\PrintPreferences;
use App\Domains\Platform\Customization\Level;
use App\Domains\Platform\Customization\Scope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\PrintSamples;
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
    use PrintSamples;
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

    /**
     * @return array<string, array{PaperSize}>
     */
    public static function papers(): array
    {
        return array_combine(
            array_map(fn (PaperSize $paper): string => $paper->value, PaperSize::cases()),
            array_map(fn (PaperSize $paper): array => [$paper], PaperSize::cases()),
        );
    }

    /**
     * Every paper's PDF, a till's receipt as much as an A4 page, with a long
     * bilingual invoice on it.
     */
    #[DataProvider('papers')]
    public function test_every_paper_is_pdfa(PaperSize $paper): void
    {
        $this->assertConforms(app(InvoicePdf::class)->render($this->sampleInvoice($this->acme, 'standard', 12), $this->acme, $paper));
    }

    /**
     * And stamped, on every paper.
     */
    #[DataProvider('papers')]
    public function test_every_paper_stays_pdfa_stamped(PaperSize $paper): void
    {
        $credentials = $this->selfSignedCredentials(0x1234567890);
        app(CredentialStore::class)->put($this->acme->id, null, CredentialStore::PCSID, [
            'privateKey' => $credentials['privateKey'],
            'pcsid' => $credentials['certificate'],
            'secret' => 'secret',
        ]);

        $pdf = app(InvoicePdf::class)->render($this->sampleInvoice($this->acme, 'simplified', 12), $this->acme, $paper);

        $this->assertStringContainsString('/ETSI.CAdES.detached', $pdf);
        $this->assertConforms($pdf);
    }

    /**
     * Arabic first, right to left, is the default every case above prints;
     * an organization that chose English first stays PDF/A on every paper.
     */
    #[DataProvider('papers')]
    public function test_english_first_stays_pdfa(PaperSize $paper): void
    {
        app(PrintPreferences::class)->setLanguageOrder($this->acme, PrintPreferences::ENGLISH_FIRST);

        $this->assertConforms(app(InvoicePdf::class)->render($this->sampleInvoice($this->acme, 'standard', 12), $this->acme->fresh(), $paper));
    }

    /**
     * With the organization's own QR printed beside ZATCA's, on a sheet and
     * on a roll.
     */
    public function test_an_extra_qr_stays_pdfa(): void
    {
        app(InvoiceExtraQr::class)->setChoices($this->acme, [PaperSize::A4->value => InvoiceExtraQr::INVOICE_LINK, PaperSize::Roll58->value => InvoiceExtraQr::INVOICE_LINK]);
        $invoice = $this->sampleInvoice($this->acme, 'simplified', 3);

        $this->assertConforms(app(InvoicePdf::class)->render($invoice, $this->acme->fresh(), PaperSize::A4));
        $this->assertConforms(app(InvoicePdf::class)->render($invoice, $this->acme->fresh(), PaperSize::Roll58));
    }

    /**
     * @return array<string, array{TemplateLayout, PaperSize}>
     */
    public static function layoutsAndPapers(): array
    {
        $cases = [];

        foreach (TemplateLayout::cases() as $layout) {
            foreach (PaperSize::cases() as $paper) {
                $cases[$layout->value.' on '.$paper->value] = [$layout, $paper];
            }
        }

        return $cases;
    }

    /**
     * Every invoice template layout on every paper, stamped, with a logo,
     * the template's colour and all its own text: the coloured band and
     * fills of the modern layout as much as the black-and-white receipt.
     */
    #[DataProvider('layoutsAndPapers')]
    public function test_every_layout_stays_pdfa_stamped(TemplateLayout $layout, PaperSize $paper): void
    {
        Storage::fake(BrandAssets::DISK);
        $credentials = $this->selfSignedCredentials(0x1234567890);
        app(CredentialStore::class)->put($this->acme->id, null, CredentialStore::PCSID, [
            'privateKey' => $credentials['privateKey'],
            'pcsid' => $credentials['certificate'],
            'secret' => 'secret',
        ]);
        app(ThemeService::class)->save((string) $this->acme->id, ThemeDefinition::fromArray(['colors' => ['primary' => '#14B8A6']]), ['logo' => UploadedFile::fake()->image('logo.png', 240, 80)]);
        app(InvoiceTemplates::class)->save(Level::Organization, Scope::organization((string) $this->acme->id), [
            'layout' => $layout->value,
            'logo_position' => 'center',
            'header_en' => 'Open Sunday to Thursday', 'header_ar' => 'مفتوح من الأحد إلى الخميس',
            'terms_en' => 'Payment within 30 days', 'terms_ar' => 'السداد خلال ثلاثين يوماً',
            'footer_en' => 'Thank you', 'footer_ar' => 'شكراً لكم',
            'bank_name' => 'Riyad Bank', 'bank_iban' => 'SA0380000000608010167519', 'bank_account_name' => 'Acme Trading', 'bank_swift' => 'RIBLSARI',
            'column_item_code' => true,
        ], $this->acme, null);

        $pdf = app(InvoicePdf::class)->render($this->sampleInvoice($this->acme, 'standard', 12), $this->acme->fresh(), $paper);

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
