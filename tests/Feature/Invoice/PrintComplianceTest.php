<?php

declare(strict_types=1);

namespace Tests\Feature\Invoice;

use App\Domains\Auth\Models\User;
use App\Domains\Compliance\Countries\SaudiArabia\DTOs\QrCodeData;
use App\Domains\Compliance\Countries\SaudiArabia\Signing\QrCodeGenerator;
use App\Domains\Compliance\Countries\SaudiArabia\Signing\TlvEncoder;
use App\Domains\Compliance\Engine\Services\JurisdictionResolver;
use App\Domains\Invoice\Enums\PaperSize;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Invoice\Services\BusinessQrCodes;
use App\Domains\Invoice\Services\InvoiceExtraQr;
use App\Domains\Invoice\Services\InvoicePdf;
use App\Domains\Organization\Models\Organization;
use App\Support\QrCodeRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\PrintSamples;
use Tests\TestCase;

/**
 * A Saudi invoice stays the same compliant document on every paper.
 *
 * The QR carries the TLV the invoice stored, unchanged, wherever it is
 * drawn; the PDF carries the XML that was signed or cleared, byte for byte;
 * a till's receipt keeps every field a simplified tax invoice must show; and
 * an organization's own QR never touches ZATCA's.
 */
class PrintComplianceTest extends TestCase
{
    use PrintSamples;
    use RefreshDatabase;

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
     * The XML inside the PDF is the cleared XML for a standard invoice and
     * the signed one for a simplified invoice, exactly, at every size.
     */
    #[DataProvider('papers')]
    public function test_every_paper_embeds_the_xml(PaperSize $paper): void
    {
        foreach (['standard', 'simplified'] as $type) {
            $invoice = $this->sampleInvoice($this->organization, $type, 2);
            $pdf = app(InvoicePdf::class)->render($invoice, $this->organization, $paper);

            $this->assertSame($type === 'standard' ? $invoice->cleared_xml : $invoice->signed_xml, $this->embeddedXml($pdf));
        }
    }

    /**
     * The QR on the print view, the receipt and the invoice page is the
     * stored payload, drawn as it is: the nine Phase 2 tags in, the nine
     * out, with or without the organization's own QR beside it.
     */
    #[DataProvider('papers')]
    public function test_the_qr_is_the_stored_payload(PaperSize $paper): void
    {
        $code = app(BusinessQrCodes::class)->create((string) $this->organization->id, 'link', ['url' => 'https://example.test']);
        app(InvoiceExtraQr::class)->setChoices($this->organization, [$paper->value => $code->id]);
        $invoice = $this->sampleInvoice($this->organization, 'simplified', 2);
        $stored = (string) $invoice->qr_code;
        $drawn = app(QrCodeRenderer::class)->svg($stored);

        $receipt = app(JurisdictionResolver::class)->forRules($this->organization)->presentation()->receipt($invoice, $this->organization);
        $this->assertSame($stored, $receipt->qrPayload);
        $this->assertSame([1, 2, 3, 4, 5, 6, 7, 8, 9], array_keys((new TlvEncoder)->decode($receipt->qrPayload)));

        $this->portal()->get(route('portal.invoices.print', ['invoiceId' => $invoice->id, 'paper' => $paper->value]))
            ->assertOk()
            ->assertSee($drawn, false)
            ->assertSee('data-print-extra-qr', false);
        $this->portal()->get(route('portal.invoices.show', $invoice->id))->assertOk()->assertSee($drawn, false);
        $this->assertSame($stored, $invoice->fresh()->qr_code);
    }

    /** A Phase 1 invoice's QR keeps its five tags, on paper as stored. */
    public function test_phase_one_keeps_five_tags(): void
    {
        $invoice = $this->sampleInvoice($this->organization, 'simplified', 1);
        $phaseOne = (new QrCodeGenerator(new TlvEncoder))->generatePhase1(new QrCodeData(
            sellerName: (string) $this->organization->name_ar,
            vatNumber: '300000000000003',
            timestamp: '2026-10-17T10:15:30Z',
            invoiceTotal: (string) $invoice->total,
            vatTotal: (string) $invoice->tax_amount,
        ));
        Invoice::withoutTenantScope(fn () => Invoice::query()->whereKey($invoice->id)->toBase()->update(['qr_code' => $phaseOne]));
        $invoice = $invoice->fresh(['lines']);

        $receipt = app(JurisdictionResolver::class)->forRules($this->organization)->presentation()->receipt($invoice, $this->organization);

        $this->assertSame([1, 2, 3, 4, 5], array_keys((new TlvEncoder)->decode($receipt->qrPayload)));
        $this->portal()->get(route('portal.invoices.print', ['invoiceId' => $invoice->id, 'paper' => '58mm']))
            ->assertSee(app(QrCodeRenderer::class)->svg($phaseOne), false);
    }

    /**
     * What a simplified tax invoice must show, on the till's receipt - the
     * one Receipt the browser, the PDF and the printer image all draw:
     *
     * - seller's name and VAT number: VAT Implementing Regulations
     *   Art. 53(8)(b)-(c); BT-27, BT-31 in ZATCA's data dictionary
     * - date and time of issue: Art. 53(8)(a); BT-2, KSA-25
     * - invoice number: BT-1, the sequential number of every e-invoice
     * - VAT total: Art. 53(8)(f); BT-110
     * - total including VAT: Art. 53(8)(e); BT-112
     * - the QR code: ZATCA's Controls, Requirements, Technical
     *   Specifications and Procedural Rules for the E-Invoicing Regulation,
     *   which require it on every simplified tax invoice; KSA-14
     */
    public function test_a_receipt_keeps_required_fields(): void
    {
        $this->travelTo(now()->setTime(10, 15, 30));
        $invoice = $this->sampleInvoice($this->organization, 'simplified', 2);
        $receipt = app(JurisdictionResolver::class)->forRules($this->organization)->presentation()->receipt($invoice, $this->organization);
        $values = [];

        foreach ($receipt->sections as $section) {
            $values[] = (string) $section->name;

            foreach ($section->rows as $row) {
                $values[] = $row->label->en.'='.$row->value;
            }
        }

        foreach ($receipt->totals as $row) {
            $values[] = $row->label->en.'='.$row->value;
        }

        $this->assertContains($this->organization->documentName(), $values);
        $this->assertContains('VAT number=300000000000003', $values);
        $this->assertContains('Invoice number='.$invoice->invoice_number, $values);
        $this->assertContains('Issue date and time='.$invoice->issue_date->toDateString().' 13:15:30', $values);
        $this->assertContains('VAT='.number_format((float) $invoice->tax_amount, 2).' SAR', $values);
        $this->assertContains('Total due='.number_format((float) $invoice->total, 2).' SAR', $values);
        $this->assertSame((string) $invoice->qr_code, $receipt->qrPayload);
    }

    private function portal(): static
    {
        return $this->actingAs($this->member)->withSession(['portal_org_id' => $this->organization->id]);
    }

    /** The embedded file's bytes, inflated. */
    private function embeddedXml(string $pdf): string
    {
        $this->assertSame(1, preg_match('#/Type /EmbeddedFile\b.*?stream\r?\n(.*?)\r?\nendstream#s', $pdf, $match));

        return (string) gzuncompress($match[1]);
    }
}
