<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance\UnitedArabEmirates;

use App\Domains\Compliance\Countries\UnitedArabEmirates\Document\PeppolPreparer;
use App\Domains\Compliance\Countries\UnitedArabEmirates\Rules\MandatoryFields;
use App\Domains\Compliance\Countries\UnitedArabEmirates\Rules\PintAe;
use App\Domains\Invoice\Models\Invoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Ministry's mandatory-fields list, and what each scenario adds to it,
 * checked before a document is stored.
 */
class PintMandatoryFieldsTest extends TestCase
{
    use PintInvoices;
    use RefreshDatabase;

    /**
     * Mandatory Fields v1.0: 51 fields on a tax invoice (4.1), 49 on a
     * commercial one (4.2). A field dropped or added here is a departure
     * from the published list.
     */
    public function test_lists_match_the_ministry_counts(): void
    {
        $organization = $this->uaeOrganization();
        $preparer = app(PeppolPreparer::class);
        $fields = app(MandatoryFields::class);

        $tax = $fields->fieldsFor($preparer->document($this->uaeInvoice($organization)));
        $commercial = $fields->fieldsFor($preparer->document($this->uaeInvoice($organization, [], null, ['document_category' => 'commercial_invoice'])));

        $this->assertCount(51, $tax);
        $this->assertCount(49, $commercial);
        $this->assertArrayHasKey('Buyer tax identifier', $tax);
        $this->assertArrayHasKey('Buyer legal registration identifier type', $commercial);
        $this->assertArrayNotHasKey('VAT line amount in AED', $commercial);
    }

    public function test_complete_invoice_has_no_problems(): void
    {
        $this->assertSame([], $this->problems($this->uaeInvoice($this->uaeOrganization())));
    }

    public function test_missing_settings_are_named(): void
    {
        $problems = $this->problems($this->uaeInvoice($this->uaeOrganization(['trn' => null, 'endpoint_id' => null, 'legal_registration_id' => null])));

        $this->assertContains('Seller tax identifier is missing.', $problems);
        $this->assertContains('Seller electronic address is missing.', $problems);
        $this->assertContains('Seller legal registration identifier is missing.', $problems);
    }

    public function test_subdivision_must_be_an_emirate(): void
    {
        $problems = $this->problems($this->uaeInvoice($this->uaeOrganization(['emirate' => 'Dubai'])));

        $this->assertSame(1, count(array_filter($problems, fn (string $p): bool => str_starts_with($p, 'Seller country subdivision: "DUBAI" is not an emirate code'))));
    }

    public function test_trn_shape_is_checked(): void
    {
        $problems = $this->problems($this->uaeInvoice($this->uaeOrganization(), ['buyer_vat_number' => '300000000000003']));

        $this->assertContains('Buyer tax identifier: "300000000000003" is not a TRN (15 digits, starting 1, ending 03).', $problems);
    }

    public function test_reverse_charge_needs_goods_and_gtin(): void
    {
        $problems = $this->problems($this->uaeInvoice($this->uaeOrganization(), [], [
            $this->uaeLine(['tax_category' => 'AE', 'tax_rate' => 0, 'tax_amount' => 0]),
        ]));

        $this->assertContains('Line 1: a reverse-charge line names the type of goods ('.implode(', ', PintAe::REVERSE_CHARGE_GOODS).').', $problems);
        $this->assertContains("Line 1: a reverse-charge line needs the item's GTIN.", $problems);
    }

    public function test_credit_note_needs_reason_and_reference(): void
    {
        $problems = $this->problems($this->uaeInvoice($this->uaeOrganization(), ['document_type' => 'credit_note']));

        $this->assertContains('A tax credit note needs its reason code ('.implode(', ', PintAe::CREDIT_REASONS).').', $problems);
        $this->assertContains('A credit note names the invoice it corrects, unless it is a volume discount.', $problems);
        $this->assertNotContains('Payment due date is missing.', $problems);
    }

    /** IBR-122-AE: a commercial invoice carries no standard-rated line. */
    public function test_commercial_invoice_refuses_standard_rate(): void
    {
        $problems = $this->problems($this->uaeInvoice($this->uaeOrganization(), [], null, [
            'document_category' => 'commercial_invoice',
            'buyer_legal_registration' => ['type' => 'TL', 'id' => 'CN-1', 'authority' => 'DED'],
        ]));

        $this->assertSame(['Line 1: a commercial invoice cannot carry tax category S.'], $problems);
    }

    public function test_scenarios_name_what_they_need(): void
    {
        $problems = $this->problems($this->uaeInvoice($this->uaeOrganization(), ['currency' => 'USD'], null, ['transaction_flags' => '10011000']));

        $this->assertContains('An invoice in USD needs its exchange rate to AED.', $problems);
        $this->assertContains('A free zone supply names the beneficiary (beneficiary_id).', $problems);
        $this->assertContains('A summary invoice states its period: the supply date and supply end date.', $problems);
    }

    public function test_transaction_flags_are_eight_bits(): void
    {
        $problems = $this->problems($this->uaeInvoice($this->uaeOrganization(), [], null, ['transaction_flags' => '0010']));

        $this->assertContains('Invoice transaction type code: eight characters, each 0 or 1 (IBR-154-AE).', $problems);
    }

    /** @return list<string> */
    private function problems(Invoice $invoice): array
    {
        return app(PeppolPreparer::class)->problems($invoice);
    }
}
