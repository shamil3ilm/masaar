<?php

declare(strict_types=1);

namespace Tests\Feature\Books;

use App\Domains\Books\Enums\PaymentMethod;
use App\Domains\Books\Models\BadDebtRelief;
use App\Domains\Books\Services\CustomerPayments;
use App\Domains\Books\Services\Receivables;
use App\Domains\Books\Services\SalesBadDebts;
use App\Domains\Books\Services\VatPeriods;
use App\Domains\Compliance\Countries\SaudiArabia\Reports\VatReturn as SaudiReturn;
use App\Domains\Invoice\Enums\AdjustmentKind;
use App\Domains\Invoice\Enums\InvoiceStatus;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Invoice\Models\InvoiceAdjustment;
use App\Domains\Invoice\Models\InvoiceLine;
use App\Domains\Organization\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * KSA VAT Implementing Regulations Art 40(9), worked by hand: paid after
 * bad-debt relief, "Tax calculated on the subsequent Consideration received
 * will become payable and must be accounted for in the Tax Return for the
 * Tax Period in which the payment occurs, and a new Tax invoice must be
 * issued to reflect the additional amount received."
 *
 * 10,000 + VAT 1,500 = 11,500 sold thirteen months ago, declared, written
 * off. 2,300 is paid this month: a fifth. The return gives back 300 of the
 * 1,500 (box 1: -8,000 / -1,200 left), and a new tax invoice is drafted for
 * 2,000 + 300 = 2,300, linked to the recovery and the original. Issued, it
 * is not counted again in the return, nor owed again.
 */
class BadDebtRecoveryTest extends TestCase
{
    use BooksFixtures;
    use RefreshDatabase;

    public function test_payment_after_relief_drafts_invoice(): void
    {
        $acme = $this->saudi();
        $original = $this->relieved($acme);

        app(CustomerPayments::class)->record($original, '2300', CarbonImmutable::today(), PaymentMethod::BankTransfer);

        $recovery = BadDebtRelief::acrossTenants()->where('kind', BadDebtRelief::RECOVERY)->sole();
        $adjustment = InvoiceAdjustment::acrossTenants()->sole();
        $invoice = Invoice::acrossTenants()->with('lines')->findOrFail($adjustment->new_invoice_id);

        $this->assertSame([AdjustmentKind::BadDebtRecovery, $original->id, $recovery->id], [$adjustment->kind, $adjustment->invoice_id, $adjustment->bad_debt_relief_id]);
        $this->assertSame(['2000.00', '300.00', '2300.00'], [(string) $invoice->subtotal, (string) $invoice->tax_amount, (string) $invoice->total]);
        $this->assertSame([InvoiceStatus::Draft, 'invoice', 'simplified', 'Gulf Supplies'], [$invoice->status, $invoice->document_type->value, $invoice->type->value, $invoice->buyer_name]);
        $this->assertStringContainsString('Art 40(9)', (string) $invoice->notes);
        $this->assertSame([0.0, -8000.0, -1200.0], $this->box1($acme));
    }

    public function test_issued_recovery_invoice_counts_once(): void
    {
        $acme = $this->saudi();
        $original = $this->relieved($acme);
        app(CustomerPayments::class)->record($original, '2300', CarbonImmutable::today(), PaymentMethod::BankTransfer);
        $invoice = Invoice::acrossTenants()->findOrFail(InvoiceAdjustment::acrossTenants()->sole()->new_invoice_id);

        $invoice->forceFill(['status' => 'issued', 'invoice_number' => 'INV-REC-1', 'icv' => 9, 'hash' => base64_encode(hash('sha256', 'rec', true))])->save();

        $this->assertSame([0.0, -8000.0, -1200.0], $this->box1($acme));
        $this->assertNull(app(Receivables::class)->asAt((string) $acme->id, CarbonImmutable::today())->firstWhere('number', 'INV-REC-1'));
    }

    public function test_sale_page_links_new_invoice(): void
    {
        $acme = $this->saudi();
        $original = $this->relieved($acme);
        app(CustomerPayments::class)->record($original, '2300', CarbonImmutable::today(), PaymentMethod::BankTransfer);

        $this->asRequest(fn () => $this->as($acme, 'accountant')->get(route('portal.books.invoices.show', $original->id))
            ->assertOk()->assertSee('data-recovery-invoice', false));
    }

    /** A sale 13 months old, its period filed, written off as a bad debt. */
    private function relieved(Organization $organization): Invoice
    {
        $sold = CarbonImmutable::now()->subMonths(13)->startOfMonth();
        $invoice = Invoice::acrossTenants()->create([
            'org_id' => $organization->id, 'invoice_number' => 'INV-1', 'type' => 'simplified', 'document_type' => 'invoice',
            'status' => 'draft', 'issue_date' => $sold->addDays(2)->toDateString(), 'currency' => 'SAR',
            'buyer_name' => 'Gulf Supplies', 'buyer_name_ar' => 'الخليج للتوريدات',
            'subtotal' => '10000.00', 'tax_amount' => '1500.00', 'total' => '11500.00',
        ]);
        InvoiceLine::query()->create([
            'invoice_id' => $invoice->id, 'description' => 'Consulting', 'description_ar' => 'استشارات', 'quantity' => '1',
            'unit_price' => '10000.00', 'net_amount' => '10000.00', 'tax_rate' => '15', 'tax_amount' => '1500.00', 'tax_category' => 'S', 'line_total' => '11500.00',
        ]);
        $invoice->forceFill(['status' => 'issued', 'icv' => 1, 'hash' => base64_encode(hash('sha256', 'INV-1', true)), 'qr_code' => base64_encode('AQVTZWxsZXI=')])->save();

        $draft = app(SaudiReturn::class)->draft((string) $organization->id, $sold, $sold->endOfMonth()->startOfDay());
        app(VatPeriods::class)->file((string) $organization->id, $sold, $sold->endOfMonth()->startOfDay(), $sold->endOfMonth()->addDays(20), $draft, null);
        app(SalesBadDebts::class)->claim($organization, $invoice, ['written_off_on' => CarbonImmutable::today(), 'certificate' => 'CPA-2026-14', 'unrelated' => true]);

        return $invoice->fresh(['lines']);
    }

    /** @return array{0: float|null, 1: float|null, 2: float|null} */
    private function box1(Organization $organization): array
    {
        $box = app(SaudiReturn::class)->draft((string) $organization->id, CarbonImmutable::now()->startOfMonth(), CarbonImmutable::now()->endOfMonth()->startOfDay())->box('1');

        return [$box->amount, $box->adjustment, $box->vat];
    }
}
