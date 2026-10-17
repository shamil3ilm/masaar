<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance\UnitedArabEmirates;

use App\Domains\Compliance\Countries\UnitedArabEmirates\Document\PintMapper;
use App\Domains\Compliance\Countries\UnitedArabEmirates\DTOs\PintDocument;
use App\Domains\Compliance\Countries\UnitedArabEmirates\Enums\PintDocumentCategory;
use App\Domains\Compliance\Countries\UnitedArabEmirates\Enums\PintTaxCategory;
use App\Domains\Compliance\Countries\UnitedArabEmirates\Rules\PintAe;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\ComplianceProfile;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * An invoice, its organization's UAE settings and its jurisdiction data, as
 * the values a PINT AE document carries.
 */
class PintMapperTest extends TestCase
{
    use PintInvoices;
    use RefreshDatabase;

    public function test_seller_comes_from_uae_settings(): void
    {
        $document = $this->map($this->uaeInvoice($this->uaeOrganization()));

        $this->assertSame(PintDocumentCategory::TaxInvoice, $document->category);
        $this->assertSame('380', $document->typeCode);
        $this->assertSame(PintAe::BILLING, $document->customizationId);
        $this->assertSame('urn:peppol:bis:billing', $document->profileId);
        $this->assertSame('0235:1001234567', $document->seller->endpoint());
        $this->assertSame('100123456700003', $document->seller->vatId);
        $this->assertNull($document->seller->taxRegistrationId);
        $this->assertSame(['TL', 'CN-1234567', 'Dubai Economy and Tourism'], [$document->seller->legalType, $document->seller->legalId, $document->seller->legalAuthority]);
        $this->assertSame('DXB', $document->seller->address->subdivision);
        $this->assertSame('0235:1007654321', $document->buyer->endpoint());
        $this->assertSame('AUH', $document->buyer->address->subdivision);
        $this->assertSame('AE070331234567890123456', $document->paymentAccount);
        $this->assertSame(['1000.00', '50.00', '1050.00'], [$document->taxExclusiveAmount, $document->taxAmount, $document->payableAmount]);
    }

    /**
     * Three lines of 0.10 at 5%: rounding each line's VAT gives 0.03, the
     * exact category VAT is 0.015, and the UAE rounds only the document
     * total - to 0.02.
     */
    public function test_tax_rounds_at_document_total(): void
    {
        $line = $this->uaeLine(['quantity' => 1, 'unit_price' => 0.10, 'net_amount' => 0.10, 'tax_amount' => 0.01, 'line_total' => 0.11]);
        $document = $this->map($this->uaeInvoice($this->uaeOrganization(), [], [$line, $line, $line]));

        $this->assertSame('0.015', $document->subtotals[0]->taxAmount);
        $this->assertSame('0.02', $document->taxAmount);
    }

    public function test_foreign_currency_states_dirham_totals(): void
    {
        $document = $this->map($this->uaeInvoice($this->uaeOrganization(), ['currency' => 'USD', 'exchange_rate' => '3.6725']));

        $this->assertSame('AED', $document->taxCurrency);
        $this->assertSame('3.6725', $document->exchangeRate);
        $this->assertSame('183.63', $document->taxAmountInTaxCurrency);
        $this->assertSame('3856.13', $document->totalInTaxCurrency);
        $this->assertSame(['183.63', '3856.13'], [$document->lines[0]->vatAed, $document->lines[0]->totalAed]);
    }

    public function test_self_billing_swaps_the_parties(): void
    {
        $document = $this->map($this->uaeInvoice($this->uaeOrganization(), ['is_self_billed' => true]));

        $this->assertSame(PintDocumentCategory::SelfBilledInvoice, $document->category);
        $this->assertSame(['389', PintAe::SELF_BILLING, 'urn:peppol:bis:selfbilling'], [$document->typeCode, $document->customizationId, $document->profileId]);
        $this->assertSame('Noor Electronics LLC', $document->seller->name);
        $this->assertSame('Gulf Trading LLC', $document->buyer->name);
    }

    public function test_export_defaults_to_reserved_endpoint(): void
    {
        $document = $this->map($this->uaeInvoice(
            $this->uaeOrganization(),
            ['buyer_address' => ['street' => '5th Avenue', 'city' => 'New York', 'country_code' => 'US']],
            null,
            ['transaction_flags' => '00000001', 'buyer_endpoint' => null, 'buyer_subdivision' => 'NY'],
        ));

        $this->assertSame('0235:'.PintAe::EXPORT_BUYER, $document->buyer->endpoint());
        $this->assertSame('US', $document->delivery?->countryCode);
        $this->assertSame('NY', $document->delivery?->subdivision);
    }

    public function test_deemed_supply_uses_reserved_endpoint(): void
    {
        $document = $this->map($this->uaeInvoice($this->uaeOrganization(), [], null, ['transaction_flags' => '01000000']));

        $this->assertSame('0235:'.PintAe::DEEMED_SUPPLY_BUYER, $document->buyer->endpoint());
    }

    /**
     * 100 off 1000.00 standard-rated and 99.99 zero-rated: 90.91 and 9.09,
     * which add back to the 100.
     */
    public function test_discount_is_shared_by_category(): void
    {
        $document = $this->map($this->uaeInvoice($this->uaeOrganization(), ['discount_amount' => '100.00'], [
            $this->uaeLine(),
            $this->uaeLine(['quantity' => 3, 'unit_price' => 33.33, 'net_amount' => 99.99, 'tax_category' => 'Z', 'tax_rate' => 0, 'tax_amount' => 0, 'line_total' => 99.99]),
        ]));

        $this->assertSame(['90.91', '9.09'], array_map(fn ($a) => $a->amount, $document->allowances));
        $this->assertSame(['909.09', '90.90'], array_map(fn ($s) => $s->taxableAmount, $document->subtotals));
        $this->assertSame('999.99', $document->taxExclusiveAmount);
    }

    public function test_seller_without_trn_carries_tin(): void
    {
        $document = $this->map($this->uaeInvoice(
            $this->uaeOrganization(['trn' => null]),
            [],
            [$this->uaeLine(['tax_category' => 'O', 'tax_rate' => 0, 'tax_amount' => 0])],
            ['document_category' => 'commercial_invoice'],
        ));

        $this->assertSame('480', $document->typeCode);
        $this->assertNull($document->seller->vatId);
        $this->assertSame('1001234567', $document->seller->taxRegistrationId);
        $this->assertNull($document->lines[0]->rate);
    }

    /**
     * Reverse charge and the margin scheme state the standard rate and
     * charge nothing.
     */
    public function test_reverse_charge_states_rate_charges_nothing(): void
    {
        $document = $this->map($this->uaeInvoice($this->uaeOrganization(), [], [
            $this->uaeLine(['tax_category' => 'AE', 'tax_rate' => 0, 'tax_amount' => 0]),
        ], ['lines' => ['1' => ['goods_type' => 'DL8.48.8.2', 'item_id' => '09506000134352']]]));

        $line = $document->lines[0];

        $this->assertSame([PintTaxCategory::ReverseCharge, '5', '0.00'], [$line->category, $line->rate, $line->vatAed]);
        $this->assertSame(['DL8.48.8.2', '09506000134352'], [$line->goodsType, $line->standardItemId]);
        $this->assertSame('0.00', $document->taxAmount);
    }

    public function test_debit_note_is_refused(): void
    {
        $this->expectException(DomainException::class);

        $this->map($this->uaeInvoice($this->uaeOrganization(), ['document_type' => 'debit_note']));
    }

    public function test_unknown_tax_category_is_refused(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('"X" is not a UAE tax category');

        $this->map($this->uaeInvoice($this->uaeOrganization(), [], [$this->uaeLine(['tax_category' => 'X'])]));
    }

    public function test_category_must_match_document_type(): void
    {
        $this->expectException(DomainException::class);

        $this->map($this->uaeInvoice($this->uaeOrganization(), [], null, ['document_category' => 'tax_credit_note']));
    }

    private function map(Invoice $invoice): PintDocument
    {
        $settings = ComplianceProfile::withoutTenantScope(fn () => ComplianceProfile::query()->where('org_id', $invoice->org_id)->firstOrFail());

        return app(PintMapper::class)->map($invoice, $settings, new PintAe);
    }
}
