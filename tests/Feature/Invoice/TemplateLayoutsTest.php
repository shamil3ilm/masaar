<?php

declare(strict_types=1);

namespace Tests\Feature\Invoice;

use App\Domains\Auth\Models\User;
use App\Domains\Branding\DTOs\ThemeDefinition;
use App\Domains\Branding\Services\ThemeService;
use App\Domains\Invoice\Enums\PaperSize;
use App\Domains\Invoice\Services\BusinessQrCodes;
use App\Domains\Invoice\Services\InvoiceExtraQr;
use App\Domains\Invoice\Services\InvoicePdf;
use App\Domains\Invoice\Services\ReceiptPrinting;
use App\Domains\Invoice\Templates\Contrast;
use App\Domains\Invoice\Templates\InvoiceTemplates;
use App\Domains\Invoice\Templates\TemplateLayout;
use App\Domains\Organization\Models\Organization;
use App\Domains\Platform\Customization\Level;
use App\Domains\Platform\Customization\Scope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\PrintSamples;
use Tests\TestCase;

/**
 * The template layouts - classic, modern, compact and thermal - on every
 * paper, in the browser's print view and in the PDF; the template's own
 * text on each; and a colour that stays legible and prints in black and
 * white.
 */
class TemplateLayoutsTest extends TestCase
{
    use PrintSamples;
    use RefreshDatabase;

    private const TEXT = [
        'header_en' => 'Open Sunday to Thursday',
        'header_ar' => 'مفتوح من الأحد إلى الخميس',
        'footer_en' => 'Thank you for your business',
        'footer_ar' => 'شكراً لتعاملكم معنا',
        'terms_en' => 'Payment within 30 days',
        'terms_ar' => 'السداد خلال ثلاثين يوماً',
        'bank_name' => 'Riyad Bank',
        'bank_iban' => 'SA0380000000608010167519',
        'bank_account_name' => 'Gulf Horizon Trading',
        'bank_swift' => 'RIBLSARI',
    ];

    private Organization $organization;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = $this->sampleOrganization();
        $this->member = User::factory()->create();
        $this->member->organizations()->attach($this->organization->id, ['role' => 'member', 'status' => 'active']);
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
     * Each layout prints on each paper, in the browser and in the PDF, with
     * the template's header, terms, bank details and footer; the PDF carries
     * the signed XML exactly. A roll, and the thermal layout on a sheet,
     * print the receipt's column.
     */
    #[DataProvider('layoutsAndPapers')]
    public function test_each_layout_prints_on_each_paper(TemplateLayout $layout, PaperSize $paper): void
    {
        $this->template(['layout' => $layout->value, ...self::TEXT]);
        $invoice = $this->sampleInvoice($this->organization, 'simplified', 3);
        $receipt = $paper->isRoll() || $layout === TemplateLayout::Thermal;

        $view = (string) $this->portal()->get(route('portal.invoices.print', ['invoiceId' => $invoice->id, 'paper' => $paper->value]))->assertOk()->getContent();
        $pdf = app(InvoicePdf::class)->html($invoice, $this->organization, $paper);

        foreach (['view' => $view, 'PDF' => $pdf] as $where => $html) {
            foreach (['Open Sunday to Thursday', 'مفتوح من الأحد إلى الخميس', 'Payment within 30 days', 'السداد خلال ثلاثين يوماً', 'SA03 8000 0000 6080 1016 7519', 'RIBLSARI', 'Thank you for your business', 'شكراً لتعاملكم معنا'] as $text) {
                $this->assertStringContainsString(e($text), $html, "{$text} is missing from the {$where}");
            }

            $this->assertSame($receipt, str_contains($html, 'class="receipt"'), $where);
        }

        if (! $receipt) {
            $this->assertStringContainsString('tpl-'.$layout->value, $view);
            $this->assertStringContainsString(route('theme.print-accent', ['accent' => ltrim(app(InvoiceTemplates::class)->for($this->organization)->accent, '#')]), $view);
        }

        $this->assertSame($invoice->signed_xml, $this->embeddedXml(app(InvoicePdf::class)->render($invoice, $this->organization, $paper)));
    }

    /**
     * The modern layout puts the title on a band of the colour; classic and
     * compact print the title in it; the receipt is black on white whatever
     * the colour.
     */
    public function test_the_colour_reaches_each_layout(): void
    {
        $invoice = $this->sampleInvoice($this->organization, 'standard', 2);

        foreach (['modern' => 'class="band"', 'classic' => '.title { font-size: 15pt; font-weight: bold; color: #1E3A8A; }', 'compact' => '.title { font-size: 15pt; font-weight: bold; color: #1E3A8A; }'] as $layout => $marker) {
            $this->template(['layout' => $layout, 'accent' => '#1E3A8A']);
            $html = app(InvoicePdf::class)->html($invoice, $this->organization, PaperSize::A4);

            $this->assertStringContainsString($marker, $html, $layout);
            $this->assertStringContainsString('#1E3A8A', $html, $layout);
        }

        $this->template(['layout' => 'thermal', 'accent' => '#1E3A8A']);

        foreach ([PaperSize::A4, PaperSize::Roll80] as $paper) {
            $this->assertStringNotContainsString('#1E3A8A', app(InvoicePdf::class)->html($invoice, $this->organization, $paper));
        }

        $this->assertStringNotContainsString('print-accent', (string) $this->portal()->get(route('portal.invoices.print', ['invoiceId' => $invoice->id, 'paper' => '80mm']))->getContent());
    }

    /**
     * WCAG 2.2's contrast: a colour too light to read on white, or to read
     * white on, is refused; the theme's colour, chosen for screens, is
     * darkened until it reads.
     */
    public function test_a_light_colour_is_refused(): void
    {
        $this->assertEqualsWithDelta(21.0, Contrast::ratio('#000000', '#FFFFFF'), 0.01);
        $this->assertFalse(Contrast::legible('#14B8A6'));
        $this->assertTrue(Contrast::legible('#0F766E'));
        $this->assertTrue(Contrast::legible(Contrast::legibleShade('#FDE047')));

        try {
            $this->template(['accent' => '#FDE047']);
            $this->fail('A yellow was accepted.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('accent', $e->errors());
        }

        app(ThemeService::class)->save((string) $this->organization->id, ThemeDefinition::fromArray(['colors' => ['primary' => '#14B8A6']]));
        $accent = app(InvoiceTemplates::class)->for($this->organization)->accent;

        $this->assertTrue(Contrast::legible($accent));
        $this->assertGreaterThanOrEqual(Contrast::MINIMUM, Contrast::ratio($accent, '#FFFFFF'));
    }

    /**
     * The print view's colour is a stylesheet of one variable, from six hex
     * digits and nothing else.
     */
    public function test_the_colour_is_a_stylesheet(): void
    {
        $this->get(route('theme.print-accent', ['accent' => '1e3a8a']))
            ->assertOk()
            ->assertHeader('Content-Type', 'text/css; charset=UTF-8')
            ->assertSeeText(':root{--print-accent:#1E3A8A;}', false);

        $this->get('/theme/print-accent/red;}x')->assertNotFound();
    }

    /**
     * The template can leave the organization's own QR off; the country's
     * stays on every paper.
     */
    public function test_the_business_qr_can_be_hidden(): void
    {
        $code = app(BusinessQrCodes::class)->create((string) $this->organization->id, 'link', ['url' => 'https://example.test']);
        app(InvoiceExtraQr::class)->setChoices($this->organization, [PaperSize::A4->value => $code->id, PaperSize::Roll80->value => $code->id]);
        $invoice = $this->sampleInvoice($this->organization, 'simplified', 1);
        $print = fn (string $paper): string => (string) $this->portal()->get(route('portal.invoices.print', ['invoiceId' => $invoice->id, 'paper' => $paper]))->getContent();

        $this->assertStringContainsString('data-print-extra-qr', $print('a4'));

        $this->template(['business_qr' => false]);

        foreach (['a4', '80mm'] as $paper) {
            $this->assertStringNotContainsString('data-print-extra-qr', $print($paper));
            $this->assertStringContainsString('data-print-qr', $print($paper));
        }
    }

    /**
     * The till's printer image carries the template's lines too, and grows
     * by them.
     */
    public function test_the_printer_image_carries_them(): void
    {
        $invoice = $this->sampleInvoice($this->organization, 'simplified', 2);
        $printing = app(ReceiptPrinting::class);
        $plain = imagesy($printing->image($invoice, $this->organization, null, '80mm'));

        $this->template(self::TEXT);

        $this->assertGreaterThan($plain, imagesy($printing->image($invoice, $this->organization, null, '80mm')));
    }

    /** @param  array<string, mixed>  $values */
    private function template(array $values): void
    {
        app(InvoiceTemplates::class)->save(Level::Organization, Scope::organization((string) $this->organization->id), $values, $this->organization, null);
    }

    private function portal(): static
    {
        return $this->actingAs($this->member)->withSession(['portal_org_id' => $this->organization->id]);
    }

    private function embeddedXml(string $pdf): string
    {
        $this->assertSame(1, preg_match('#/Type /EmbeddedFile\b.*?stream\r?\n(.*?)\r?\nendstream#s', $pdf, $match));

        return (string) gzuncompress($match[1]);
    }
}
