<?php

declare(strict_types=1);

namespace Tests\Feature\Invoice;

use App\Domains\Auth\Models\User;
use App\Domains\Compliance\Engine\Services\JurisdictionResolver;
use App\Domains\Invoice\Enums\PaperSize;
use App\Domains\Invoice\Services\InvoicePdf;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\PrintSamples;
use Tests\TestCase;

/**
 * An invoice printed on every paper a business uses: three office sheets
 * and two till rolls, the PDF and the browser's print view alike.
 */
class PaperSizeTest extends TestCase
{
    use PrintSamples;
    use RefreshDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = $this->sampleOrganization();
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
     * The page is the paper's width, and a sheet its length; the XML is
     * inside whatever the paper.
     */
    #[DataProvider('papers')]
    public function test_the_pdf_is_the_paper(PaperSize $paper): void
    {
        $pdf = app(InvoicePdf::class)->render($this->sampleInvoice($this->organization, 'simplified', 3), $this->organization, $paper);
        [$width, $length] = $this->pageMm($pdf);

        $this->assertEqualsWithDelta($paper->widthMm(), $width, 0.1);

        if ($paper->heightMm() !== null) {
            $this->assertEqualsWithDelta($paper->heightMm(), $length, 0.1);
        }

        $this->assertStringContainsString('/AFRelationship /Alternative', $pdf);
        $this->assertStringContainsString('pdfaid:part>3<', $pdf);
    }

    /**
     * A roll is cut where the receipt ends: more lines, more paper, and a
     * short receipt is not padded to a sheet's length.
     */
    public function test_a_roll_follows_its_receipt(): void
    {
        $pdf = app(InvoicePdf::class);
        [, $short] = $this->pageMm($pdf->render($this->sampleInvoice($this->organization, 'simplified', 2), $this->organization, PaperSize::Roll80));
        [, $long] = $this->pageMm($pdf->render($this->sampleInvoice($this->organization, 'simplified', 20), $this->organization, PaperSize::Roll80));

        $this->assertGreaterThan($short + 100, $long);
        $this->assertLessThan(297, $short);
        $this->assertSame(1, preg_match_all('#/Type /Page[^s]#', $pdf->render($this->sampleInvoice($this->organization, 'simplified', 20), $this->organization, PaperSize::Roll58)));
    }

    /**
     * The receipt keeps every field the full page has, standard invoice's
     * breakdown included, in both languages.
     */
    public function test_the_receipt_keeps_every_field(): void
    {
        $invoice = $this->sampleInvoice($this->organization, 'standard', 2);
        $receipt = app(JurisdictionResolver::class)->forRules($this->organization)->presentation()->receipt($invoice, $this->organization);
        $html = view('print.receipt', ['receipt' => $receipt, 'qr' => '<img alt="">', 'logo' => null])->render();

        foreach ([
            'Tax invoice', 'فاتورة ضريبية', $this->organization->name_ar, '300000000000003', '1010101010',
            'King Fahd Road', 'Invoice number', $invoice->invoice_number, 'تاريخ ووقت الإصدار', 'Supply date',
            'مجموعة النور للضيافة', '310122393500003', 'Prince Sultan Road',
            'قهوة عربية محمصة', 'Arabic coffee', 'الإجمالي غير شامل', 'نسبة الضريبة', 'مبلغ الضريبة',
            'Taxable amount', 'إجمالي المبلغ المستحق', number_format((float) $invoice->total, 2).' SAR',
            'Counter '.$invoice->icv, $invoice->hash,
        ] as $field) {
            $this->assertStringContainsString(e($field), $html, $field.' is missing from the receipt');
        }
    }

    /**
     * Pure black on white: a thermal head cannot print a grey.
     */
    public function test_the_receipt_has_no_grey(): void
    {
        $css = file_get_contents(resource_path('css/print/receipt.css')).file_get_contents(resource_path('css/print/receipt-58mm.css'));

        preg_match_all('/#[0-9a-f]{3,6}\b/i', (string) $css, $colours);

        $this->assertNotEmpty($colours[0]);
        $this->assertSame([], array_values(array_diff(array_map('strtolower', $colours[0]), ['#000', '#fff'])));
    }

    /**
     * The print view asks for the paper by its own stylesheet, and a roll
     * gets the receipt.
     */
    #[DataProvider('papers')]
    public function test_the_print_view_sets_the_page(PaperSize $paper): void
    {
        $member = User::factory()->create();
        $member->organizations()->attach($this->organization->id, ['role' => 'member', 'status' => 'active']);
        $invoice = $this->sampleInvoice($this->organization, 'simplified', 2);

        $response = $this->actingAs($member)->withSession(['portal_org_id' => $this->organization->id])
            ->get(route('portal.invoices.print', ['invoiceId' => $invoice->id, 'paper' => $paper->value]))
            ->assertOk()
            ->assertSee('/assets/'.$paper->value.'-', false)
            ->assertSee('data-print-qr', false);

        $paper->isRoll()
            ? $response->assertSee('class="receipt"', false)->assertSee('data-roll-width="'.(int) $paper->widthMm().'"', false)
            : $response->assertDontSee('class="receipt"', false);
    }

    /**
     * @return array{0: float, 1: float} the first page's width and length in millimetres
     */
    private function pageMm(string $pdf): array
    {
        $this->assertSame(1, preg_match('#/MediaBox \[0 0 ([\d.]+) ([\d.]+)\]#', $pdf, $box));

        return [(float) $box[1] * 25.4 / 72, (float) $box[2] * 25.4 / 72];
    }
}
