<?php

declare(strict_types=1);

namespace Tests\Feature\Invoice;

use App\Domains\Auth\Models\User;
use App\Domains\Compliance\Countries\SaudiArabia\Document\DocumentBuilder;
use App\Domains\Compliance\Countries\SaudiArabia\Signing\TlvEncoder;
use App\Domains\Compliance\Engine\Services\JurisdictionResolver;
use App\Domains\Invoice\Enums\PaperSize;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Invoice\Services\InvoiceDrafter;
use App\Domains\Invoice\Services\InvoicePdf;
use App\Domains\Invoice\Services\ReceiptPrinting;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\PrintSamples;
use Tests\Fixtures\SigningCredentials;
use Tests\TestCase;

/**
 * A final invoice with everything the data on an invoice can carry beyond
 * its lines, printed: the buyer's references, the due date and payment
 * terms, a line's discount and charge, a charge on the whole document with
 * its VAT, and a prepayment deducted down to the amount due.
 *
 * Every paper - the print view at each size and the markup the PDF is drawn
 * from, and on a roll the one Receipt the browser, the PDF and the printer
 * image draw - shows the same figures the signed document states. The amount
 * due is the QR's total (tag 4), as in ZATCA's advance-payment sample.
 */
class PrintedChargesTest extends TestCase
{
    use PrintSamples;
    use RefreshDatabase;
    use SigningCredentials;

    private Organization $organization;

    private User $member;

    private Invoice $invoice;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = $this->sampleOrganization();
        $this->member = User::factory()->create();
        $this->member->organizations()->attach($this->organization->id, ['role' => 'member', 'status' => 'active']);
        $this->invoice = $this->finalInvoice();
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
     * 2 x 100 less 20 plus a 5.00 packing charge is 185.00; a 25.00
     * delivery at 15% (VAT 3.75) makes 210.00 taxable and 31.50 VAT, so
     * 241.50 in all, of which 115.00 was prepaid: 126.50 is due.
     */
    public function test_the_amounts_are_the_documents(): void
    {
        $this->assertSame(['241.50', '115.00', '126.50'], [(string) $this->invoice->total, (string) $this->invoice->prepaid_amount, $this->invoice->payable()]);
        $this->assertSame('126.50', (new TlvEncoder)->decode((string) $this->invoice->qr_code)[4]);
    }

    #[DataProvider('papers')]
    public function test_every_paper_prints_them(PaperSize $paper): void
    {
        $print = (string) $this->actingAs($this->member)->withSession(['portal_org_id' => $this->organization->id])
            ->get(route('portal.invoices.print', ['invoiceId' => $this->invoice->id, 'paper' => $paper->value]))
            ->assertOk()->getContent();
        $pdf = app(InvoicePdf::class)->html($this->invoice, $this->organization, $paper);

        foreach (['print view' => $print, 'PDF' => $pdf] as $copy => $html) {
            $text = html_entity_decode(preg_replace('/\s+/u', ' ', strip_tags($html)) ?? '');

            foreach ([
                'PO-7781', 'CTR-2026-14', 'BUYER-REF-9', // the references
                'Net 30 days from the invoice date', now()->addDays(30)->toDateString(), // terms and due date
                '-20.00 SAR', // the line's discount
                'Packing', '+5.00 SAR', // the line's charge
                'Delivery', '25.00 SAR', 'VAT 15%', '3.75 SAR', // the document charge and its VAT
                '210.00 SAR', // the taxable amount, charge included
                'Prepayment ADV-1', '-115.00 SAR', // the prepayment deducted
                'Amount due', '126.50 SAR', // what is left to pay
            ] as $expected) {
                $this->assertStringContainsString($expected, $text, "{$paper->value} {$copy} is missing \"{$expected}\"");
            }
        }
    }

    /**
     * On a roll the receipt's amount due is the QR's total, in the Receipt
     * every roll draws and in the printer image made from it.
     */
    public function test_a_receipt_agrees_with_the_qr(): void
    {
        $receipt = app(JurisdictionResolver::class)->forRules($this->organization)->presentation()->receipt($this->invoice, $this->organization);
        $totals = [];

        foreach ($receipt->totals as $row) {
            $totals[$row->label->en] = $row->value;
        }

        $tag4 = (new TlvEncoder)->decode($receipt->qrPayload)[4];
        $this->assertSame($tag4.' SAR', $totals['Amount due']);
        $this->assertSame('241.50 SAR', $totals['Total']);
        $this->assertSame('-115.00 SAR', $totals['Prepayment ADV-1']);

        $printing = app(ReceiptPrinting::class);
        $this->assertNotSame('', $printing->png($printing->image($this->invoice, $this->organization, null, '58mm')));
    }

    private function finalInvoice(): Invoice
    {
        $drafter = app(InvoiceDrafter::class);
        $buyer = [
            'type' => 'standard',
            'buyer_name' => 'Al Noor Hospitality Group',
            'buyer_vat_number' => '310122393500003',
            'buyer_address' => ['street' => 'Prince Sultan Road', 'building_number' => '7788', 'district' => 'Al Rawdah', 'city' => 'Jeddah', 'postal_code' => '23435', 'country_code' => 'SA'],
            'issue_date' => now()->toDateString(),
            'supply_date' => now()->toDateString(),
            'currency' => 'SAR',
        ];

        $advance = $drafter->draft([...$buyer, 'invoice_number' => 'ADV-1', 'document_type' => 'prepayment',
            'lines' => [['description' => 'Advance', 'quantity' => 1, 'unit_price' => '100.00']]], (string) $this->organization->id);
        $advance->forceFill(['status' => 'issued', 'hash' => base64_encode('ADV-1'), 'issued_at' => now()->subDay()])->save();

        $final = $drafter->draft([...$buyer,
            'invoice_number' => 'FINAL-1',
            'document_type' => 'invoice',
            'order_reference' => 'PO-7781',
            'contract_reference' => 'CTR-2026-14',
            'buyer_reference' => 'BUYER-REF-9',
            'payment_terms' => 'Net 30 days from the invoice date',
            'payment_means_code' => '30',
            'lines' => [['description' => 'Chair', 'quantity' => 2, 'unit_price' => '100.00', 'discount_amount' => '20.00',
                'charges' => [['reason' => 'Packing', 'reason_code' => 'PC', 'amount' => '5.00']]]],
            'charges' => [['reason' => 'Delivery', 'reason_code' => 'DL', 'amount' => '25.00', 'tax_category' => 'S', 'tax_rate' => '15']],
            'prepayments' => [['invoice_number' => 'ADV-1']],
        ], (string) $this->organization->id, null, ['due_date' => now()->addDays(30)->toDateString()]);

        $final = Invoice::withoutTenantScope(fn () => Invoice::query()->with(['lines.charges', 'charges', 'prepayments'])->findOrFail($final->id));
        $credentials = $this->selfSignedCredentials(0x5150);
        $sealed = app(DocumentBuilder::class)->generateComplianceData($final, $this->organization, null, $credentials['privateKey'], $credentials['certificate']);

        $final->forceFill([
            'status' => 'issued',
            'icv' => 2,
            'hash' => $sealed['hash'],
            'qr_code' => $sealed['qr_code'],
            'signed_xml' => $sealed['signed_xml'],
            'cleared_xml' => $sealed['signed_xml'],
            'issued_at' => now(),
        ])->save();

        return $final->fresh(['lines.charges', 'charges', 'prepayments']);
    }
}
