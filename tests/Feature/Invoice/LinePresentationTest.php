<?php

declare(strict_types=1);

namespace Tests\Feature\Invoice;

use App\Domains\Auth\Models\User;
use App\Domains\Compliance\Engine\Services\JurisdictionResolver;
use App\Domains\Invoice\Enums\InvoiceType;
use App\Domains\Invoice\Enums\PaperSize;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Invoice\Support\UnitCode;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\PrintSamples;
use Tests\TestCase;

/**
 * An invoice line on paper: its unit beside the quantity in both languages,
 * a discount column only when a line has one, document charges among the
 * totals, and on a standard invoice every amount ZATCA requires per line.
 */
class LinePresentationTest extends TestCase
{
    use PrintSamples;
    use RefreshDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = $this->sampleOrganization();
    }

    public function test_units_print_in_both_languages(): void
    {
        $this->assertSame('kg / كغ', UnitCode::printed('KGM'));
        $this->assertSame('hr / ساعة', UnitCode::printed('HUR'));
        $this->assertSame('pc / قطعة', UnitCode::printed('PCE'));
        $this->assertSame('pc / قطعة', UnitCode::printed(null));
        $this->assertSame('ZZ9', UnitCode::printed('ZZ9'));
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
     * A standard invoice's line keeps its quantity and unit, unit price,
     * taxable amount, VAT rate, VAT amount and total with VAT on every
     * paper: VAT Implementing Regulations Art. 53(5); BT-129, BT-146,
     * BT-131, BT-152, KSA-11 and KSA-12 in ZATCA's data dictionary.
     */
    #[DataProvider('papers')]
    public function test_a_standard_line_keeps_its_amounts(PaperSize $paper): void
    {
        $invoice = $this->sampleInvoice($this->organization, 'standard', 1);
        $line = $invoice->lines->first();
        $html = $this->html($invoice, $paper);

        foreach ([
            'unit' => $paper->isRoll() ? '3 وحدة / unit' : 'unit / وحدة',
            'unit price' => '42.50 SAR',
            'net' => '127.50 SAR',
            'rate' => '15%',
            'vat' => number_format((float) $line->tax_amount, 2).' SAR',
            'total' => number_format((float) $line->line_total, 2).' SAR',
        ] as $what => $value) {
            $this->assertStringContainsString(e($value), $html, $what.' is missing on '.$paper->value);
        }
    }

    /**
     * The discount column appears only when a line has a discount, and a
     * document charge is a row of the totals with its VAT rate.
     */
    #[DataProvider('papers')]
    public function test_discounts_and_charges_show_when_present(PaperSize $paper): void
    {
        $invoice = $this->sampleInvoice($this->organization, 'standard', 2);

        $this->assertStringNotContainsString('الخصم', $this->html($invoice, $paper));

        $invoice->lines->first()->setAttribute('discount_amount', '5.00');
        $invoice->setAttribute('charges', [['reason' => 'Delivery', 'amount' => '25.00', 'tax_rate' => '15.00']]);
        $html = $this->html($invoice, $paper);

        $this->assertStringContainsString('الخصم', $html);
        $this->assertStringContainsString('-5.00 SAR', $html);
        $this->assertStringContainsString('Delivery', $html);
        $this->assertStringContainsString('(15%)', $html);
        $this->assertStringContainsString('25.00 SAR', $html);
    }

    /** The portal's invoice page shows the unit beside the quantity. */
    public function test_the_invoice_page_shows_units(): void
    {
        $member = User::factory()->create();
        $member->organizations()->attach($this->organization->id, ['role' => 'member', 'status' => 'active']);
        $invoice = $this->sampleInvoice($this->organization, 'simplified', 1);

        $this->actingAs($member)->withSession(['portal_org_id' => $this->organization->id])
            ->get(route('portal.invoices.show', $invoice->id))
            ->assertOk()
            ->assertSee('unit / وحدة');
    }

    /** What the paper's layout prints, as markup. */
    private function html(Invoice $invoice, PaperSize $paper): string
    {
        $country = app(JurisdictionResolver::class)->forRules($this->organization)->presentation();

        return $paper->isRoll()
            ? view('print.receipt', ['receipt' => $country->receipt($invoice, $this->organization), 'qr' => '', 'logo' => null])->render()
            : view($country->pageView(true), [
                'invoice' => $invoice,
                'organization' => $this->organization,
                'simplified' => $invoice->type === InvoiceType::Simplified,
                'qr' => null,
                'logo' => null,
                'paper' => $paper,
            ])->render();
    }
}
