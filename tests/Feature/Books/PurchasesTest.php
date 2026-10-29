<?php

declare(strict_types=1);

namespace Tests\Feature\Books;

use App\Domains\Books\Enums\PurchaseTreatment;
use App\Domains\Books\Models\PurchaseInvoice;
use App\Domains\Books\Services\Purchases;
use App\Domains\Organization\Models\Organization;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Purchase invoices and supplier credit notes: their amounts in both
 * currencies, the input VAT that may be deducted, and the checks on entry.
 */
class PurchasesTest extends TestCase
{
    use BooksFixtures;
    use RefreshDatabase;

    private Organization $acme;

    protected function setUp(): void
    {
        parent::setUp();

        $this->acme = $this->saudi();
    }

    /** 1,000.00 at 15%: 150.00 VAT, all of it deductible. */
    public function test_standard_purchase_is_fully_deductible(): void
    {
        $purchase = $this->purchase($this->acme, $this->supplier($this->acme), [[PurchaseTreatment::Standard, '1000']]);

        $this->assertSame(['1000.00', '150.00', '1150.00', '150.00'], [$purchase->net_tax, $purchase->vat_tax, $purchase->gross_tax, $purchase->deductible_vat_tax]);
        $this->assertSame('15.00', $purchase->lines[0]->vat_rate);
    }

    /**
     * Partly for exempt sales, 60% deductible: 150.00 of VAT, 90.00 of it
     * deducted (KSA VAT Implementing Regulations Art 51).
     */
    public function test_deductible_share_limits_input_vat(): void
    {
        $purchase = $this->purchase($this->acme, $this->supplier($this->acme), [[PurchaseTreatment::Standard, '1000', '150']], ['share' => '60']);

        $this->assertSame('90.00', $purchase->deductible_vat_tax);
    }

    /**
     * USD 2,000 of services from abroad at 3.75: SAR 7,500.00, VAT
     * 1,125.00 assessed here and deducted; nothing owed the supplier but
     * the 7,500.00.
     */
    public function test_reverse_charge_is_assessed_here(): void
    {
        $abroad = $this->supplier($this->acme, ['name' => 'Cloud Hosting Ltd', 'country_code' => 'IE', 'vat_number' => null]);
        $purchase = $this->purchase($this->acme, $abroad, [[PurchaseTreatment::ReverseCharge, '2000']], ['currency' => 'USD', 'rate' => '3.75']);

        $this->assertSame(['7500.00', '0.00', '7500.00', '1125.00'], [$purchase->net_tax, $purchase->vat_tax, $purchase->gross_tax, $purchase->deductible_vat_tax]);
        $this->assertSame(['300.00', '1125.00'], [$purchase->lines[0]->vat, $purchase->lines[0]->vat_tax]);
        $this->assertSame('3.750000', $purchase->exchange_rate);
    }

    /** Standard 500 (VAT 75), exempt 200, a capital asset of 3,000 (VAT 450). */
    public function test_mixed_lines_add_up(): void
    {
        $purchase = $this->purchase($this->acme, $this->supplier($this->acme), [
            [PurchaseTreatment::Standard, '500'],
            [PurchaseTreatment::Exempt, '200'],
            [PurchaseTreatment::Standard, '3000', null, true],
        ]);

        $this->assertSame(['3700.00', '525.00', '4225.00', '525.00'], [$purchase->net_tax, $purchase->vat_tax, $purchase->gross_tax, $purchase->deductible_vat_tax]);
        $this->assertTrue($purchase->lines[2]->is_capital_asset);
    }

    public function test_received_defaults_to_document_date(): void
    {
        $supplier = $this->supplier($this->acme);
        $purchase = $this->purchase($this->acme, $supplier, [[PurchaseTreatment::Standard, '100']], ['date' => '2026-10-05']);

        $this->assertSame('2026-10-05', $purchase->received_on->toDateString());

        $this->expectException(ValidationException::class);
        $this->purchase($this->acme, $supplier, [[PurchaseTreatment::Standard, '100']], ['date' => '2026-10-05', 'received' => '2026-10-04']);
    }

    /** A Saudi supplier, or one with no country, charges its own VAT (IR Art 47(1)). */
    public function test_local_reverse_charge_is_refused(): void
    {
        foreach (['SA', null] as $country) {
            try {
                $this->purchase($this->acme, $this->supplier($this->acme, ['country_code' => $country]), [[PurchaseTreatment::ReverseCharge, '1000']]);
                $this->fail('Reverse charge from a resident supplier was accepted.');
            } catch (ValidationException $e) {
                $this->assertStringContainsString('only for a supplier outside Saudi Arabia', $e->errors()['lines.0.treatment'][0]);
            }
        }
    }

    public function test_foreign_currency_needs_a_rate(): void
    {
        $this->expectException(ValidationException::class);

        $this->purchase($this->acme, $this->supplier($this->acme), [[PurchaseTreatment::Standard, '100']], ['currency' => 'USD']);
    }

    public function test_exempt_line_carries_no_vat(): void
    {
        $this->expectException(ValidationException::class);

        $this->purchase($this->acme, $this->supplier($this->acme), [[PurchaseTreatment::Exempt, '100', '15']]);
    }

    public function test_same_number_is_refused_twice(): void
    {
        $supplier = $this->supplier($this->acme);
        $first = $this->purchase($this->acme, $supplier, [[PurchaseTreatment::Standard, '100']], ['number' => 'A-1']);

        try {
            $this->purchase($this->acme, $supplier, [[PurchaseTreatment::Standard, '100']], ['number' => 'A-1']);
            $this->fail('A second A-1 was recorded.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('supplier_invoice_number', $e->errors());
        }

        // Void, the number is free again.
        app(Purchases::class)->void($first, 'Typed twice', null);
        $this->assertSame(PurchaseInvoice::VOID, $first->fresh()->status);
        $this->assertSame('A-1', $this->purchase($this->acme, $supplier, [[PurchaseTreatment::Standard, '100']], ['number' => 'A-1'])->supplier_invoice_number);
    }

    public function test_credited_invoice_cannot_be_voided(): void
    {
        $supplier = $this->supplier($this->acme);
        $invoice = $this->purchase($this->acme, $supplier, [[PurchaseTreatment::Standard, '100']]);
        $this->purchase($this->acme, $supplier, [[PurchaseTreatment::Standard, '20']], ['type' => 'credit_note', 'original' => $invoice->id]);

        $this->expectException(DomainException::class);

        app(Purchases::class)->void($invoice, 'Wrong', null);
    }

    /**
     * A bill of 1,000 + 150: credit notes of 600 + 90 then 400 + 60 use it
     * up exactly; one more of 0.01 is refused, as is any note taking back
     * more VAT than the bill charged.
     */
    public function test_supplier_credit_is_capped_by_bill(): void
    {
        $supplier = $this->supplier($this->acme);
        $bill = $this->purchase($this->acme, $supplier, [[PurchaseTreatment::Standard, '1000']]);
        $this->purchase($this->acme, $supplier, [[PurchaseTreatment::Standard, '600']], ['type' => 'credit_note', 'original' => $bill->id]);
        $this->purchase($this->acme, $supplier, [[PurchaseTreatment::Standard, '400']], ['type' => 'credit_note', 'original' => $bill->id]);

        try {
            $this->purchase($this->acme, $supplier, [[PurchaseTreatment::Standard, '0.01']], ['type' => 'credit_note', 'original' => $bill->id]);
            $this->fail('A credit note took back more than the bill.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('only 0.00 SAR is left', $e->errors()['original_id'][0] ?? '');
        }

        $second = $this->purchase($this->acme, $supplier, [[PurchaseTreatment::Standard, '1000']]);
        $this->expectException(ValidationException::class);
        $this->purchase($this->acme, $supplier, [[PurchaseTreatment::Standard, '100', '200']], ['type' => 'credit_note', 'original' => $second->id]);
    }

    public function test_another_organizations_supplier_is_refused(): void
    {
        $other = $this->saudi('Other Co');

        $this->expectException(ValidationException::class);

        $this->purchase($this->acme, $this->supplier($other), [[PurchaseTreatment::Standard, '100']]);
    }
}
