<?php

declare(strict_types=1);

namespace Tests\Feature\Invoice;

use App\Domains\Auth\Models\User;
use App\Domains\Compliance\Engine\Contracts\Presentation;
use App\Domains\Compliance\Engine\DTOs\PrintedField;
use App\Domains\Compliance\Engine\Services\JurisdictionResolver;
use App\Domains\Invoice\Enums\PaperSize;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Invoice\Models\InvoiceLine;
use App\Domains\Invoice\Models\Product;
use App\Domains\Invoice\Services\InvoicePdf;
use App\Domains\Invoice\Support\PrintedAmounts;
use App\Domains\Invoice\Templates\InvoiceTemplates;
use App\Domains\Invoice\Templates\PrintedColumns;
use App\Domains\Invoice\Templates\TemplateLayout;
use App\Domains\Organization\Models\Organization;
use App\Domains\Platform\Customization\Level;
use App\Domains\Platform\Customization\Scope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Compliance\UnitedArabEmirates\PintInvoices;
use Tests\Fixtures\PrintSamples;
use Tests\TestCase;

/**
 * Every field a country requires on a printed invoice is printed, in every
 * template layout and on every paper, whatever the template's columns say.
 *
 * Each country declares its fields (Presentation::printedFields) with the
 * rule that requires them; this renders the browser's print view and the
 * PDF's markup for a tax invoice, a simplified one and a credit note, and
 * finds each required field's value in each.
 */
class PrintedFieldsTest extends TestCase
{
    use PintInvoices;
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
     * A Saudi invoice: the fields of ZATCA's data dictionary marked Required
     * on a printed copy, and those Required if populated that the invoice
     * populates. The template hides every optional column it can.
     */
    #[DataProvider('layoutsAndPapers')]
    public function test_saudi_fields_print_in_every_layout(TemplateLayout $layout, PaperSize $paper): void
    {
        app(InvoiceTemplates::class)->save(Level::Organization, Scope::organization((string) $this->organization->id), [
            'layout' => $layout->value,
            'column_unit' => false,
            'column_discount' => false,
            'column_vat_rate' => false,
        ], $this->organization, null);
        $fields = $this->country($this->organization)->printedFields();

        foreach ([$this->standard(), $this->sampleInvoice($this->organization, 'simplified', 2), $this->creditNote()] as $invoice) {
            $type = $invoice->type->value;
            $expected = $this->saudiValues($invoice);
            $pages = [
                'print view' => $this->portal()->get(route('portal.invoices.print', ['invoiceId' => $invoice->id, 'paper' => $paper->value]))->assertOk()->getContent(),
                'PDF' => app(InvoicePdf::class)->html($invoice, $this->organization, $paper),
            ];

            $this->assertStringContainsString('data-print-qr', (string) $pages['print view']);
            // Every caption's directive compiled: none is printed as text.
            $this->assertStringNotContainsString('@else', (string) $pages['print view']);
            $this->assertStringContainsString('<img src="data:image/png;base64,', $pages['PDF']);

            foreach ($fields as $field) {
                $this->assertArrayHasKey($field->key, $expected, 'The test does not know how to find '.$field->key);
                [$given, $values] = $expected[$field->key];

                if (! $field->requiredOn($type, $given)) {
                    continue;
                }

                foreach ($pages as $where => $html) {
                    foreach ($values as $value) {
                        $this->assertStringContainsString($value, (string) $html, "{$field->key} ({$field->source}) is missing from the {$type} {$invoice->document_type->value}'s {$where} in {$layout->value} on {$paper->value}");
                    }
                }
            }
        }
    }

    /**
     * Asking for no discount and no VAT rate leaves both on a tax invoice
     * whose line has a discount; the unit and the item code are the
     * template's to choose.
     */
    public function test_columns_cannot_hide_required_ones(): void
    {
        $templates = app(InvoiceTemplates::class);
        $scope = Scope::organization((string) $this->organization->id);
        $invoice = $this->standard();
        $fields = $this->country($this->organization)->printedFields();

        $templates->save(Level::Organization, $scope, ['column_unit' => false, 'column_discount' => false, 'column_vat_rate' => false, 'column_item_code' => true], $this->organization, null);
        $columns = PrintedColumns::for($templates->for($this->organization), $fields, $invoice);

        $this->assertSame(['unit' => false, 'discount' => true, 'vat_rate' => true, 'item_code' => true], $columns);

        $html = app(InvoicePdf::class)->html($invoice, $this->organization);
        $this->assertStringContainsString('SKU-COFFEE-500', $html);
        $this->assertStringNotContainsString('unit / وحدة', $html);

        // The template's own choice decides where the country requires nothing.
        $simplified = $this->sampleInvoice($this->organization, 'simplified', 1);
        $this->assertFalse(PrintedColumns::for($templates->for($this->organization), $fields, $simplified)['vat_rate']);
        $templates->save(Level::Organization, $scope, ['column_vat_rate' => true], $this->organization, null);
        $this->assertTrue(PrintedColumns::for($templates->for($this->organization), $fields, $simplified)['vat_rate']);
        $this->assertStringContainsString('15%', app(InvoicePdf::class)->html($simplified, $this->organization));

        $this->assertSame([PrintedField::LINE_DISCOUNT, PrintedField::LINE_VAT_RATE], array_values(array_map(fn (PrintedField $field): string => $field->key, PrintedColumns::locks($fields))));
    }

    /**
     * English first, the seller's and buyer's VAT numbers and the seller's
     * registration keep their captions in the browser's print view, as they
     * do Arabic first.
     */
    public function test_english_first_keeps_every_caption(): void
    {
        app(InvoiceTemplates::class)->save(Level::Organization, Scope::organization((string) $this->organization->id), ['language' => 'english_first'], $this->organization, null);
        $invoice = $this->standard();

        $html = (string) $this->portal()->get(route('portal.invoices.print', ['invoiceId' => $invoice->id, 'paper' => 'a4']))->assertOk()->getContent();

        $this->assertSame(2, substr_count($html, '<span dir="ltr">VAT</span> <span dir="rtl" lang="ar">الرقم الضريبي</span>'));
        $this->assertStringContainsString('<span dir="ltr">CR</span> <span dir="rtl" lang="ar">السجل التجاري</span>', $html);
    }

    /**
     * Every field each country declares names the rule that requires it.
     */
    public function test_each_field_cites_its_rule(): void
    {
        config(['peppol.countries.AE.enabled' => true]);

        foreach ([$this->organization, $this->uaeOrganization()] as $organization) {
            $fields = $this->country($organization)->printedFields();
            $this->assertNotEmpty($fields);

            foreach ($fields as $field) {
                $this->assertMatchesRegularExpression('/Art\. \d+|BT-\d+|KSA-\d+|Guidelines v[\d.]+, [\d.]+/', $field->source, $field->key);
                $this->assertNotSame('', $field->label->ar, $field->key);
                $this->assertNotSame([], [...$field->always, ...$field->whenGiven], $field->key);
            }
        }
    }

    /**
     * A UAE tax invoice to a buyer not yet on electronic invoicing, printed
     * as the guidelines require (Electronic Invoicing Guidelines v1.1,
     * 10.2.2, p23): every particular of the Executive Regulation's Art. 59(1)
     * (a)-(l) (MoF consolidated text to Cabinet Decision No. 149 of 2026,
     * p51) its invoices have, in every layout on every paper - one in
     * dirhams with a discount and an earlier date of supply, one in dollars
     * with a reverse-charge line under Clause 3 of Article 48 of the
     * Decree-Law, and a Tax Credit Note with Art. 60(1)(e)-(g) (pp54-55).
     */
    #[DataProvider('layoutsAndPapers')]
    public function test_uae_fields_print_in_every_layout(TemplateLayout $layout, PaperSize $paper): void
    {
        config(['peppol.countries.AE.enabled' => true]);
        Queue::fake();
        $gulf = $this->uaeOrganization();
        $this->member->organizations()->attach($gulf->id, ['role' => 'member', 'status' => 'active']);
        app(InvoiceTemplates::class)->save(Level::Organization, Scope::organization((string) $gulf->id), [
            'layout' => $layout->value, 'column_unit' => false, 'column_discount' => false, 'column_vat_rate' => false,
        ], $gulf, null);
        $fields = $this->country($gulf)->printedFields();
        $reserved = ['buyer_endpoint' => '0235:9900000098'];

        $invoices = [
            'dirhams' => $this->uaeInvoice($gulf, ['supply_date' => '2026-10-05'], [
                $this->uaeLine(),
                $this->uaeLine(['description' => 'Cabling, installed', 'item_name' => 'Cabling', 'quantity' => 1, 'unit_code' => 'C62', 'unit_price' => 300, 'discount_amount' => 100, 'net_amount' => 200, 'tax_amount' => 10, 'line_total' => 210]),
            ], $reserved),
            'dollars' => $this->uaeInvoice($gulf, ['currency' => 'USD', 'exchange_rate' => '3.6725'], [
                $this->uaeLine(),
                $this->uaeLine(['description' => 'Mobile phones', 'item_name' => 'Phones', 'quantity' => 1, 'unit_code' => 'H87', 'unit_price' => 400, 'net_amount' => 400, 'tax_category' => 'AE', 'tax_rate' => 0, 'tax_amount' => 0, 'line_total' => 400]),
            ], [...$reserved, 'lines' => ['2' => ['goods_type' => 'DL8.48.3.1']]]),
        ];
        // A credit of 100 AED net on the dirham invoice (1,200 AED net).
        $invoices['credit note'] = $this->uaeInvoice($gulf, ['document_type' => 'credit_note', 'billing_ref' => $invoices['dirhams']->invoice_number, 'due_date' => null], [
            $this->uaeLine(['quantity' => 1, 'unit_price' => 100, 'net_amount' => 100, 'tax_amount' => 5, 'line_total' => 105]),
        ], [...$reserved, 'credit_reason' => 'DL8.61.1.C']);

        foreach ($invoices as $name => $invoice) {
            $expected = $this->uaeValues($invoice, $name);
            $pages = [
                'print view' => (string) $this->actingAs($this->member)->withSession(['portal_org_id' => $gulf->id])
                    ->get(route('portal.invoices.print', ['invoiceId' => $invoice->id, 'paper' => $paper->value]))->assertOk()->getContent(),
                'PDF' => app(InvoicePdf::class)->html($invoice, $gulf, $paper),
            ];

            foreach ($pages as $where => $html) {
                // No QR code: the UAE's e-invoices feature none (5.3).
                $this->assertStringNotContainsString('data-print-qr>', $html, $where);
            }

            foreach ($fields as $field) {
                $this->assertArrayHasKey($field->key, $expected, 'The test does not know how to find '.$field->key);
                [$given, $values] = $expected[$field->key];

                if (! $field->requiredOn('standard', $given)) {
                    continue;
                }

                foreach ($pages as $where => $html) {
                    foreach ($values as $value) {
                        $this->assertStringContainsString(e($value), $html, "{$field->key} ({$field->source}) is missing from the {$name} invoice's {$where} in {$layout->value} on {$paper->value}");
                    }
                }
            }
        }
    }

    /**
     * Where each UAE field's value is found, and whether the invoice has one.
     *
     * @return array<string, array{bool, list<string>}>
     */
    private function uaeValues(Invoice $invoice, string $name): array
    {
        $dollars = $name === 'dollars';
        $credit = $name === 'credit note';

        return [
            'title' => [true, [$credit ? 'Tax Credit Note' : 'Tax Invoice']],
            'seller_name' => [true, ['Gulf Trading LLC']],
            'seller_address' => [true, ['Sheikh Zayed Road', 'Dubai']],
            'seller_vat_number' => [true, ['100123456700003']],
            'buyer_name' => [true, ['Noor Electronics LLC']],
            'buyer_address' => [true, ['Khalifa Street', 'Abu Dhabi']],
            'buyer_vat_number' => [true, ['100765432100003']],
            'invoice_number' => [true, [$invoice->invoice_number]],
            'issue_date' => [true, ['2026-10-10']],
            'supply_date' => [$name === 'dirhams', ['2026-10-05']],
            'line_description' => [true, ['Consulting']],
            'line_unit_price' => [true, [$dollars ? '500.00 USD' : ($credit ? '100.00 AED' : '500.00 AED')]],
            'line_quantity' => [true, [$credit ? '1' : '2']],
            'line_vat_rate' => [true, ['5%']],
            'line_vat' => [true, [$dollars ? '183.63 AED' : ($credit ? '5.00 AED' : '50.00 AED')]],
            'line_total' => [true, [$dollars ? '3,856.13 AED' : ($credit ? '105.00 AED' : '1,050.00 AED')]],
            'line_discount' => [$name === 'dirhams', ['-100.00 AED']],
            // Art. 59(1)(j) and (k): the gross amount and the tax in AED.
            'total_with_vat' => [true, $dollars ? ['1,450.00 USD', '5,325.13 AED'] : [$credit ? '105.00 AED' : '1,260.00 AED']],
            'vat_total' => [true, $dollars ? ['50.00 USD', '183.63 AED'] : [$credit ? '5.00 AED' : '60.00 AED']],
            'vat_in_accounting_currency' => [$dollars, ['1 USD = 3.6725 AED']],
            'reverse_charge_statement' => [$dollars, ['Reverse charge: the Recipient is required to account for Tax on this supply under Clause 3 of Article 48 of Federal Decree-Law No. 8 of 2017 on Value Added Tax.']],
            // Art. 60(1)(e): 1,200 on the invoice, 1,100 correct, 100 and its 5.
            'credit_note_values' => [$credit, ['1,200.00 AED', '1,100.00 AED', '100.00 AED', '5.00 AED']],
            'credit_note_reason' => [$credit, ['The agreed consideration was altered (Art. 61(1)(c))']],
            'credit_note_reference' => [$credit, ['Invoice credited']],
        ];
    }

    /**
     * Where each field's value is found, and whether the invoice has one.
     *
     * @return array<string, array{bool, list<string>}>
     */
    private function saudiValues(Invoice $invoice): array
    {
        $money = fn ($amount): string => number_format((float) $amount, 2).' '.$invoice->currency;
        $line = $invoice->lines->first();
        $address = (array) $invoice->buyer_address;
        $adjusts = $invoice->document_type->value !== 'invoice';
        $organization = $this->organization;

        return [
            'invoice_number' => [true, [$invoice->invoice_number]],
            'issue_date' => [true, [$invoice->issue_date->toDateString()]],
            'supply_date' => [$invoice->supply_date !== null, [(string) $invoice->supply_date?->toDateString()]],
            'seller_name' => [true, [e($organization->documentName())]],
            'seller_address' => [true, [$organization->street, $organization->district, $organization->city, $organization->postal_code]],
            'seller_vat_number' => [true, [$organization->vat_number]],
            'seller_other_id' => [true, [$organization->cr_number]],
            'buyer_name' => [$invoice->documentBuyerName() !== '', [e($invoice->documentBuyerName())]],
            'buyer_address' => [$address !== [], [$address['street'] ?? '', $address['district'] ?? '', $address['city'] ?? '', $address['postal_code'] ?? '']],
            'buyer_vat_number' => [$invoice->buyer_vat_number !== null, [(string) $invoice->buyer_vat_number]],
            'buyer_other_id' => [$invoice->buyer_id !== null, [(string) $invoice->buyer_id]],
            'billing_reference' => [$adjusts, [(string) $invoice->billing_ref]],
            'adjustment_reason' => [$adjusts, [e((string) $invoice->adjustment_reason)]],
            'line_description' => [true, [e($line->documentDescription())]],
            'line_quantity' => [true, [rtrim(rtrim((string) $line->quantity, '0'), '.')]],
            'line_unit_price' => [true, [$money($line->unit_price)]],
            'line_discount' => [PrintedAmounts::lineDiscount($line) !== null, [$money(PrintedAmounts::lineDiscount($line))]],
            'line_net' => [true, [$money((float) $line->line_total - (float) $line->tax_amount)]],
            'line_vat_rate' => [true, ['15%']],
            'line_vat' => [true, [$money($line->tax_amount)]],
            'line_total' => [true, [$money($line->line_total)]],
            'exemption_reason' => [$line->exempt_reason !== null, [(string) $line->exempt_reason]],
            'document_discount' => [(float) $invoice->discount_amount > 0, [$money($invoice->discount_amount)]],
            'document_charges' => [false, []],
            'taxable_amount' => [true, [$money(PrintedAmounts::taxableAmount($invoice))]],
            'vat_total' => [true, [$money($invoice->tax_amount)]],
            'vat_in_accounting_currency' => [false, []],
            'total_with_vat' => [true, [$money($invoice->total)]],
            'prepaid_amount' => [false, []],
            'amount_due' => [false, []],
            // The QR drawn: SVG markup in the browser, a PNG for the renderer.
            'qr_code' => [true, []],
            // No insurer pays for these; PrintedSplitTest prints a split.
            'split_accounts' => [false, []],
            'split_references' => [false, []],
        ];
    }

    /** A tax invoice whose first line has a discount and a product's code, to a buyer with an identifier. */
    private function standard(): Invoice
    {
        $product = Product::acrossTenants()->create([
            'org_id' => $this->organization->id, 'name' => 'Arabic coffee', 'name_ar' => 'قهوة عربية', 'sku' => 'SKU-COFFEE-500',
            'unit_price' => '42.50', 'unit_code' => 'C62', 'tax_rate' => '15',
        ]);

        // Set while it is a draft: an issued invoice and its lines are not rewritten.
        return $this->sampleInvoice($this->organization, 'standard', 2, state: ['buyer_id' => '4030000000', 'buyer_id_scheme' => 'CRN'],
            prepare: fn (Invoice $draft) => InvoiceLine::query()->where('invoice_id', $draft->id)->orderBy('created_at')->orderBy('id')->limit(1)->get()
                ->each(fn (InvoiceLine $line) => $line->forceFill(['discount_amount' => '5.00', 'product_id' => $product->id, 'item_code' => 'SKU-COFFEE-500'])->save()));
    }

    /** A standard credit note: what it adjusts and why. */
    private function creditNote(): Invoice
    {
        return $this->sampleInvoice($this->organization, 'standard', 1, state: [
            'document_type' => 'credit_note',
            'billing_ref' => 'INV-2026-000001',
            'adjustment_reason' => 'Returned goods / بضاعة مرتجعة',
        ]);
    }

    private function country(Organization $organization): Presentation
    {
        return app(JurisdictionResolver::class)->forRules($organization)->presentation();
    }

    private function portal(): static
    {
        return $this->actingAs($this->member)->withSession(['portal_org_id' => $this->organization->id]);
    }
}
