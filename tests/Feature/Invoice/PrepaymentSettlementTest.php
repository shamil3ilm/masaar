<?php

declare(strict_types=1);

namespace Tests\Feature\Invoice;

use App\Domains\Auth\Models\User;
use App\Domains\Compliance\Countries\SaudiArabia\Document\DocumentBuilder;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Invoice\Services\VatSummary;
use App\Domains\Organization\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * A final invoice settling prepayment invoices (type 386).
 *
 * InvoiceXmlData had a place for prepayment references and the totals one
 * for the prepaid amount, and nothing filled either: an advance invoiced
 * when it was received could not be taken off the final invoice, so the
 * buyer was billed for it twice. ScenarioConformanceTest puts the final
 * document in front of ZATCA's SDK.
 */
class PrepaymentSettlementTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'prepayment-form-token';

    private Organization $acme;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->acme = Organization::create([
            'name' => 'Acme Trading', 'country' => 'SA', 'vat_number' => '399999999900003',
            'street' => 'King Fahd Road', 'building_number' => '1234', 'district' => 'Al Olaya', 'city' => 'Riyadh', 'postal_code' => '12345',
        ]);
        $this->admin = User::factory()->create();
        $this->admin->organizations()->attach($this->acme->id, ['role' => 'admin', 'status' => 'active']);
    }

    public function test_a_final_invoice_settles_a_prepayment(): void
    {
        $this->issuedPrepayment('ADV-1', '1000.00');

        $this->api(['prepayments' => [['invoice_number' => 'ADV-1']]])->assertCreated();

        $final = $this->final();
        $this->assertSame(['2300.00', '1150.00', '1150.00'], [(string) $final->total, (string) $final->prepaid_amount, $final->payable()]);
        $this->assertSame(['ADV-1', 'S', '1000.00', '150.00'], [
            $final->prepayments[0]->invoice_number, $final->prepayments[0]->tax_category,
            (string) $final->prepayments[0]->taxable_amount, (string) $final->prepayments[0]->tax_amount,
        ]);

        $xml = app(DocumentBuilder::class)->generateComplianceData($final, $this->acme)['xml'];
        $this->assertMatchesRegularExpression('#<cbc:PrepaidAmount[^>]*>1150.00</cbc:PrepaidAmount>#', $xml);
        $this->assertMatchesRegularExpression('#<cbc:PayableAmount[^>]*>1150.00</cbc:PayableAmount>#', $xml);
        $this->assertMatchesRegularExpression('#<cac:DocumentReference[^>]*>\s*<cbc:ID[^>]*>ADV-1</cbc:ID>#', $xml);
    }

    /** A prepayment is settled once, by a final tax invoice, in its own currency. */
    public function test_only_a_settleable_prepayment_is_taken(): void
    {
        $this->issuedPrepayment('ADV-1', '1000.00');
        $this->issuedPrepayment('ADV-DRAFT', '100.00', issued: false);

        $this->api(['prepayments' => [['invoice_number' => 'NOPE']]])->assertStatus(422)
            ->assertJsonValidationErrorFor('prepayments.0.invoice_number', 'error.details');
        $this->api(['prepayments' => [['invoice_number' => 'ADV-DRAFT']]])->assertStatus(422)
            ->assertJsonValidationErrorFor('prepayments.0.invoice_number', 'error.details');
        $this->api(['prepayments' => [['invoice_number' => 'ADV-1']], 'currency' => 'USD', 'exchange_rate' => '3.75'])->assertStatus(422)
            ->assertJsonValidationErrorFor('prepayments.0.invoice_number', 'error.details');
        $this->api(['prepayments' => [['invoice_number' => 'ADV-1']], 'document_type' => 'credit_note', 'billing_ref' => 'X', 'adjustment_reason' => 'Correction'])->assertStatus(422)
            ->assertJsonValidationErrorFor('prepayments', 'error.details');

        $this->api(['prepayments' => [['invoice_number' => 'ADV-1']]])->assertCreated();
        $this->api(['invoice_number' => 'FINAL-2', 'prepayments' => [['invoice_number' => 'ADV-1']]])->assertStatus(422)
            ->assertJsonValidationErrorFor('prepayments.0.invoice_number', 'error.details');
    }

    /** The advance one buyer paid does not settle another buyer's invoice. */
    public function test_another_buyers_prepayment_is_refused(): void
    {
        $this->issuedPrepayment('ADV-1', '1000.00');

        $this->api(['buyer_vat_number' => '399999999900003', 'prepayments' => [['invoice_number' => 'ADV-1']]])->assertStatus(422)
            ->assertJsonValidationErrorFor('prepayments.0.invoice_number', 'error.details');
    }

    /**
     * A credit note against the advance takes back part of it, so the final
     * invoice settles only the rest - and BT-113 still equals the categories'
     * taxable amounts and VAT (BR-KSA-80).
     */
    public function test_credited_prepayment_settles_the_rest(): void
    {
        $this->issuedPrepayment('ADV-1', '1000.00');
        $this->creditNote('ADV-1', '400.00');

        $this->api(['prepayments' => [['invoice_number' => 'ADV-1']]])->assertCreated();

        $final = $this->final();
        $this->assertSame(['690.00', '600.00', '90.00'], [(string) $final->prepaid_amount, (string) $final->prepayments[0]->taxable_amount, (string) $final->prepayments[0]->tax_amount]);
    }

    public function test_fully_credited_prepayment_is_refused(): void
    {
        $this->issuedPrepayment('ADV-1', '1000.00');
        $this->creditNote('ADV-1', '1000.00');

        $this->api(['prepayments' => [['invoice_number' => 'ADV-1']]])->assertStatus(422)
            ->assertJsonValidationErrorFor('prepayments.0.invoice_number', 'error.details');
    }

    /** BR-KSA-F-04: never a negative amount to pay. */
    public function test_prepayments_do_not_exceed_the_invoice(): void
    {
        $this->issuedPrepayment('ADV-1', '5000.00');

        $this->api(['prepayments' => [['invoice_number' => 'ADV-1']]])->assertStatus(422)
            ->assertJsonValidationErrorFor('prepayments', 'error.details');
    }

    /** The advance's VAT was declared when it was invoiced, and is not counted again. */
    public function test_the_vat_summary_counts_it_once(): void
    {
        $this->issuedPrepayment('ADV-1', '1000.00');
        $this->api(['prepayments' => [['invoice_number' => 'ADV-1']]])->assertCreated();
        $this->final()->forceFill(['status' => 'issued', 'hash' => base64_encode('final')])->save();

        $summary = app(VatSummary::class)->for($this->acme->id, CarbonImmutable::now()->startOfMonth(), CarbonImmutable::now()->endOfMonth());

        $this->assertSame([2000.0, 300.0], [$summary['boxes'][1]['base'], $summary['boxes'][1]['vat']]);
    }

    public function test_the_portal_takes_prepayment_numbers(): void
    {
        $this->issuedPrepayment('ADV-1', '400.00');
        $this->issuedPrepayment('ADV-2', '600.00');

        $this->asRequest(fn () => $this->actingAs($this->admin)->withSession(['portal_org_id' => $this->acme->id, '_token' => self::TOKEN])
            ->post('/portal/invoices', ['_token' => self::TOKEN, ...$this->payload(), 'prepayment_numbers' => 'ADV-1, ADV-2'])
            ->assertSessionHasNoErrors());

        $this->assertSame('1150.00', (string) $this->final()->prepaid_amount);
    }

    private function issuedPrepayment(string $number, string $amount, bool $issued = true): void
    {
        $this->api(['invoice_number' => $number, 'document_type' => 'prepayment', 'lines' => [['description' => 'Advance', 'description_ar' => 'دفعة مقدمة', 'quantity' => 1, 'unit_price' => $amount]]])->assertCreated();

        if ($issued) {
            Invoice::withoutTenantScope(fn () => Invoice::query()->where('invoice_number', $number)->sole())
                ->forceFill(['status' => 'issued', 'hash' => base64_encode($number), 'issued_at' => now()->subDay()])->save();
        }
    }

    /** An issued credit note against a prepayment, for a net amount at 15%. */
    private function creditNote(string $original, string $net): void
    {
        $tax = bcmul($net, '0.15', 2);
        $note = Invoice::withoutTenantScope(fn () => Invoice::create([
            'org_id' => $this->acme->id, 'invoice_number' => 'CN-'.$original, 'type' => 'standard', 'document_type' => 'credit_note',
            'status' => 'draft', 'billing_ref' => $original, 'adjustment_reason' => 'Advance refunded', 'issue_date' => now()->toDateString(),
            'currency' => 'SAR', 'subtotal' => $net, 'tax_amount' => $tax, 'total' => bcadd($net, $tax, 2),
        ]));
        $note->lines()->create(['description' => 'Advance refunded', 'quantity' => '1', 'unit_price' => $net, 'net_amount' => $net,
            'tax_rate' => '15.00', 'tax_category' => 'S', 'tax_amount' => $tax, 'line_total' => bcadd($net, $tax, 2)]);
        $note->forceFill(['status' => 'issued', 'hash' => base64_encode('CN-'.$original)])->save();
    }

    private function final(): Invoice
    {
        return Invoice::withoutTenantScope(fn () => Invoice::query()->with(['lines', 'charges', 'prepayments'])->where('document_type', 'invoice')->sole());
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'invoice_number' => 'FINAL-1',
            'type' => 'standard',
            'document_type' => 'invoice',
            'issue_date' => now()->toDateString(),
            'supply_date' => now()->toDateString(),
            'buyer_name' => 'Beta Industries',
            'buyer_name_ar' => 'شركة بيتا للصناعات',
            'buyer_vat_number' => '399999999800003',
            'buyer_address' => ['street' => 'Olaya Street', 'building_number' => '4321', 'district' => 'Al Murooj', 'city' => 'Riyadh', 'postal_code' => '11564', 'country_code' => 'SA'],
            'lines' => [['description' => 'Laptop', 'description_ar' => 'حاسوب محمول', 'quantity' => 1, 'unit_price' => '2000.00']],
            ...$overrides,
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function api(array $overrides): TestResponse
    {
        $token = JWTAuth::claims(['org_id' => $this->acme->id, 'role' => 'admin'])->fromUser($this->admin);

        return $this->withToken($token)->postJson('/api/invoices', $this->payload($overrides));
    }
}
