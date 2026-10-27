<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance\SaudiArabia;

use App\Domains\Compliance\Countries\SaudiArabia\Rules\InvoiceValidator;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Every refusal names the ZATCA rule that it enforces.
 *
 * The validator cited rules that say something else - BR-KSA-14 (buyer
 * identification) for the seller VAT number, BR-KSA-02 (no such rule) for the
 * type code, BR-KSA-35 (supply end date) for rates - so anyone following the
 * citation found nothing about the problem. Ids checked against the ZATCA
 * Schematron (BR-KSA-*) and the EN 16931 one it includes (BR-*).
 */
class ValidatorCitationTest extends TestCase
{
    use RefreshDatabase;

    private const SELLER = [
        'name' => 'Acme Trading',
        'country' => 'SA',
        'vat_number' => '300000000000003',
        'street' => 'King Fahd Road',
        'building_number' => '1234',
        'district' => 'Al Olaya',
        'city' => 'Riyadh',
        'postal_code' => '12345',
    ];

    /** BR-KSA-39 (present) and BR-KSA-40 (format). */
    public function test_seller_vat_cites_its_rules(): void
    {
        $this->assertCites('BR-KSA-39', $this->errors(['vat_number' => null]));
        $this->assertCites('BR-KSA-40', $this->errors(['vat_number' => '123']));
    }

    public function test_seller_name_and_postcode_citations(): void
    {
        $this->assertCites('BR-06', $this->errors(['name' => '']));
        $this->assertCites('BR-KSA-66', $this->errors(['postal_code' => '12']));
    }

    public function test_tax_category_cites_ksa_18(): void
    {
        $this->assertCites('BR-KSA-18', $this->errors(line: ['tax_category' => 'X']));
    }

    public function test_exemption_reason_cites_category_rule(): void
    {
        foreach (['Z' => 'BR-KSA-69', 'E' => 'BR-KSA-23', 'O' => 'BR-KSA-24'] as $category => $rule) {
            $errors = $this->errors(line: ['tax_category' => $category, 'tax_rate' => '0']);
            $this->assertCites($rule, $errors);
            $this->assertCites('BR-KSA-83', $errors);
        }

        $this->assertCites('BR-KSA-CL-04', $this->errors(line: [
            'tax_category' => 'E', 'tax_rate' => '0', 'exempt_code' => 'VATEX-XX-99', 'exempt_reason' => 'x',
        ]));
    }

    /** BR-KSA-84 for a standard rate, BR-Z-05 for zero-rated. */
    public function test_rate_rules_cite_their_ids(): void
    {
        $this->assertCites('BR-KSA-84', $this->errors(line: ['tax_rate' => '0']));
        $this->assertCites('BR-Z-05', $this->errors(line: [
            'tax_category' => 'Z', 'exempt_code' => 'VATEX-SA-32', 'exempt_reason' => 'Export of goods',
        ]));
    }

    public function test_counter_value_cites_ksa_34(): void
    {
        $this->assertCites('BR-KSA-34', $this->errors(invoice: ['icv' => -1]));
    }

    /** @param list<string> $errors */
    private function assertCites(string $rule, array $errors): void
    {
        $this->assertNotEmpty(
            array_filter($errors, fn (string $error): bool => str_starts_with($error, $rule.':')),
            "No error cites {$rule}: ".implode(' | ', $errors),
        );
    }

    /**
     * @param  array<string, mixed>  $seller
     * @param  array<string, mixed>  $line
     * @param  array<string, mixed>  $invoice
     * @return list<string>
     */
    private function errors(array $seller = [], array $line = [], array $invoice = []): array
    {
        $organization = Organization::create(self::SELLER);
        $draft = Invoice::withoutTenantScope(fn () => Invoice::create([
            'org_id' => $organization->id,
            'invoice_number' => 'INV-'.uniqid(),
            'type' => 'simplified',
            'document_type' => 'invoice',
            'status' => 'draft',
            'issue_date' => now()->toDateString(),
            'currency' => 'SAR',
            'subtotal' => '100.00',
            'discount_amount' => '0',
            'tax_amount' => '15.00',
            'total' => '115.00',
        ]));
        $draft->lines()->create(array_merge([
            'description' => 'Item',
            'quantity' => '1',
            'unit_price' => '100.00',
            'tax_rate' => '15',
            'tax_amount' => '15.00',
            'tax_category' => 'S',
            'line_total' => '115.00',
        ], $line));

        $draft = $draft->fresh(['lines'])->forceFill($invoice);
        $organization->forceFill($seller);

        return app(InvoiceValidator::class)->validate($draft, $organization)['errors'];
    }
}
