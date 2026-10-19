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
     * Every field each country declares names the rule that requires it.
     */
    public function test_each_field_cites_its_rule(): void
    {
        config(['peppol.countries.AE.enabled' => true]);

        foreach ([$this->organization, $this->uaeOrganization()] as $organization) {
            $fields = $this->country($organization)->printedFields();
            $this->assertNotEmpty($fields);

            foreach ($fields as $field) {
                $this->assertMatchesRegularExpression('/Art\. \d+|BT-\d+|KSA-\d+/', $field->source, $field->key);
                $this->assertNotSame('', $field->label->ar, $field->key);
                $this->assertNotSame([], [...$field->always, ...$field->whenGiven], $field->key);
            }
        }
    }

    /**
     * A UAE tax invoice's fields (VAT Executive Regulation Art. 59) cannot be
     * left off a printed copy in any layout or on any paper, because none is
     * printed: the buyer receives the e-invoice itself.
     */
    public function test_uae_prints_no_copy_at_all(): void
    {
        config(['peppol.countries.AE.enabled' => true]);
        Queue::fake();
        $gulf = $this->uaeOrganization();
        $this->member->organizations()->attach($gulf->id, ['role' => 'member', 'status' => 'active']);
        $invoice = $this->uaeInvoice($gulf);
        $keys = array_map(fn (PrintedField $field): string => $field->key, $this->country($gulf)->printedFields());

        foreach (['title', 'seller_vat_number', 'buyer_vat_number', 'invoice_number', 'line_vat_rate', 'total_with_vat', 'vat_total'] as $key) {
            $this->assertContains($key, $keys);
        }

        foreach (TemplateLayout::cases() as $layout) {
            app(InvoiceTemplates::class)->save(Level::Organization, Scope::organization((string) $gulf->id), ['layout' => $layout->value], $gulf, null);

            foreach (PaperSize::cases() as $paper) {
                $this->actingAs($this->member)->withSession(['portal_org_id' => $gulf->id])
                    ->get(route('portal.invoices.print', ['invoiceId' => $invoice->id, 'paper' => $paper->value]))
                    ->assertRedirect(route('portal.invoices.show', $invoice->id));
            }
        }
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
        ];
    }

    /** A tax invoice whose first line has a discount and a product's code, to a buyer with an identifier. */
    private function standard(): Invoice
    {
        $invoice = $this->sampleInvoice($this->organization, 'standard', 2);
        $product = Product::withoutTenantScope(fn () => Product::query()->create([
            'org_id' => $this->organization->id, 'name' => 'Arabic coffee', 'name_ar' => 'قهوة عربية', 'sku' => 'SKU-COFFEE-500',
            'unit_price' => '42.50', 'unit_code' => 'C62', 'tax_rate' => '15',
        ]));
        InvoiceLine::query()->whereKey($invoice->lines->first()->id)->toBase()->update(['discount_amount' => '5.00', 'product_id' => $product->id]);
        Invoice::withoutTenantScope(fn () => Invoice::query()->whereKey($invoice->id)->toBase()->update(['buyer_id' => '4030000000', 'buyer_id_scheme' => 'CRN']));

        return Invoice::withoutTenantScope(fn () => Invoice::query()->with('lines')->findOrFail($invoice->id));
    }

    /** A standard credit note: what it adjusts and why. */
    private function creditNote(): Invoice
    {
        $note = $this->sampleInvoice($this->organization, 'standard', 1);
        Invoice::withoutTenantScope(fn () => Invoice::query()->whereKey($note->id)->toBase()->update([
            'document_type' => 'credit_note',
            'billing_ref' => 'INV-2026-000001',
            'adjustment_reason' => 'Returned goods / بضاعة مرتجعة',
        ]));

        return Invoice::withoutTenantScope(fn () => Invoice::query()->with('lines')->findOrFail($note->id));
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
