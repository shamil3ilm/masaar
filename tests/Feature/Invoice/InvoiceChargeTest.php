<?php

declare(strict_types=1);

namespace Tests\Feature\Invoice;

use App\Domains\Auth\Models\User;
use App\Domains\Invoice\Exceptions\InvoiceNotEditableException;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Invoice\Models\InvoiceCharge;
use App\Domains\Invoice\Support\AdjustmentReason;
use App\Domains\Licensing\Models\License;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * Discounts and charges on a Saudi invoice, as each surface sends them: a
 * line's own discount and charges, and charges on the whole invoice -
 * delivery, a service charge - each at its own VAT category and rate.
 *
 * ScenarioConformanceTest puts the documents in front of ZATCA's SDK; this
 * holds what is kept, what is refused and what the portal offers.
 */
class InvoiceChargeTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'charge-form-token';

    private Organization $acme;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->acme = Organization::create(['name' => 'Acme', 'country' => 'SA']);
        $this->admin = User::factory()->create();
        $this->admin->organizations()->attach($this->acme->id, ['role' => 'admin', 'status' => 'active']);
    }

    /**
     * 3 x 100 less 20 plus 2% packing (6.00) is 286.00; a 25.00 delivery at
     * the standard rate and a 10% service charge (28.60) on top.
     */
    public function test_charges_are_kept_and_priced(): void
    {
        $this->api([
            'lines' => [['description' => 'Chair', 'description_ar' => 'كرسي', 'quantity' => 3, 'unit_price' => 100, 'discount_amount' => '20.00', 'charges' => [
                ['reason' => 'Packing', 'reason_code' => 'PC', 'percent' => '2'],
            ]]],
            'charges' => [
                ['reason' => 'Delivery', 'reason_code' => 'DL', 'amount' => '25.00'],
                ['reason' => 'Service charge', 'percent' => '10'],
            ],
        ])->assertCreated();

        $invoice = $this->invoice();
        $this->assertSame(['286.00', '20.00'], [(string) $invoice->lines[0]->net_amount, (string) $invoice->lines[0]->discount_amount]);
        $this->assertSame(['PC', '6.00', '300.00'], [$invoice->lines[0]->charges[0]->reason_code, (string) $invoice->lines[0]->charges[0]->amount, (string) $invoice->lines[0]->charges[0]->base_amount]);

        $charges = $invoice->charges;
        $this->assertSame(['DL', 'S', '15.00', '25.00'], [$charges[0]->reason_code, $charges[0]->tax_category, (string) $charges[0]->tax_rate, (string) $charges[0]->net_amount]);
        // Unnamed, the code is Miscellaneous; the base is the lines' total.
        $this->assertSame(['ABK', '28.60', '286.00'], [$charges[1]->reason_code, (string) $charges[1]->amount, (string) $charges[1]->base_amount]);

        // (286.00 + 25.00 + 28.60) x 1.15
        $this->assertSame(['286.00', '50.94', '390.54'], [(string) $invoice->subtotal, (string) $invoice->tax_amount, (string) $invoice->total]);
    }

    public function test_a_charge_is_amount_or_percent(): void
    {
        $this->api(['charges' => [['reason' => 'Delivery']]])->assertStatus(422)
            ->assertJsonValidationErrorFor('charges.0.amount', 'error.details');
        $this->api(['charges' => [['reason' => 'Delivery', 'amount' => '5', 'percent' => '5']]])->assertStatus(422)
            ->assertJsonValidationErrorFor('charges.0.amount', 'error.details');
        $this->api(['charges' => [['reason' => 'Delivery', 'amount' => '5', 'base_amount' => '100']]])->assertStatus(422)
            ->assertJsonValidationErrorFor('charges.0.base_amount', 'error.details');
        $this->api(['charges' => [['amount' => '5']]])->assertStatus(422)
            ->assertJsonValidationErrorFor('charges.0.reason', 'error.details');
    }

    /** BR-KSA-CL-06: a UNCL 7161 code, from ZATCA's list. */
    public function test_an_unknown_reason_code_is_refused(): void
    {
        $this->api(['charges' => [['reason' => 'Delivery', 'reason_code' => 'XYZ', 'amount' => '5']]])->assertStatus(422)
            ->assertJsonValidationErrorFor('charges.0.reason_code', 'error.details');
    }

    /**
     * BR-S-07, BR-Z-07 and BR-KSA-CL-04: the rate is the category's, and a
     * charge that is not standard-rated names its exemption from that
     * category.
     */
    public function test_a_charge_follows_its_category(): void
    {
        $this->api(['charges' => [['reason' => 'Delivery', 'amount' => '5', 'tax_category' => 'S', 'tax_rate' => 0]]])->assertStatus(422)
            ->assertJsonValidationErrorFor('charges.0.tax_rate', 'error.details');
        $this->api(['charges' => [['reason' => 'Freight', 'amount' => '5', 'tax_category' => 'Z']]])->assertStatus(422)
            ->assertJsonValidationErrorFor('charges.0.exempt_code', 'error.details');
        $this->api(['charges' => [['reason' => 'Freight', 'amount' => '5', 'tax_category' => 'Z', 'exempt_code' => 'VATEX-SA-29', 'exempt_reason' => 'Financial services']]])->assertStatus(422)
            ->assertJsonValidationErrorFor('charges.0.exempt_code', 'error.details');

        $this->api(['charges' => [['reason' => 'Freight', 'amount' => '5', 'tax_category' => 'Z', 'exempt_code' => 'VATEX-SA-34-1', 'exempt_reason' => 'International transport of goods']]])->assertCreated();
        $this->assertSame(['Z', '0.00'], [$this->invoice()->charges[0]->tax_category, (string) $this->invoice()->charges[0]->tax_rate]);
    }

    /** BR-KSA-F-04: a line's net is never below nothing. */
    public function test_line_discount_stays_within_line(): void
    {
        $this->api(['lines' => [['description' => 'Tea', 'description_ar' => 'شاي', 'quantity' => 1, 'unit_price' => 10, 'discount_amount' => '10.01']]])->assertStatus(422)
            ->assertJsonValidationErrorFor('lines.0.discount_amount', 'error.details');
    }

    public function test_the_pipeline_takes_charges(): void
    {
        License::create([
            'org_id' => $this->acme->id, 'api_key' => 'cp_test_acme', 'api_secret_hash' => License::hashSecret('secret'),
            'organization_name' => 'Acme', 'contact_email' => 'ops@masaar.test', 'environment' => 'sandbox',
            'tier' => 'professional', 'status' => 'active', 'scopes' => ['invoice.submit'],
        ]);

        $this->withHeaders(['X-API-Key' => 'cp_test_acme', 'X-API-Secret' => 'secret'])->postJson('/api/v1/pipeline/submit', [
            ...$this->payload(['charges' => [['reason' => 'Delivery', 'reason_code' => 'DL', 'amount' => '25.00']]]),
            'org_id' => $this->acme->id,
            'document_type' => 'invoice',
            'auto_submit' => false,
        ])->assertSuccessful();

        $this->assertSame('25.00', (string) $this->invoice()->charges[0]->net_amount);
    }

    /** The form offers the platform's common charges and an empty row is dropped. */
    public function test_the_portal_takes_charges(): void
    {
        $this->asRequest(fn () => $this->portal()->get('/portal/invoices/create')
            ->assertOk()
            ->assertSee('data-add-charge', false)
            ->assertSee('&quot;reason_code&quot;:&quot;DL&quot;', false));

        $this->asRequest(fn () => $this->portal()->withSession(['_token' => self::TOKEN])->post('/portal/invoices', [
            '_token' => self::TOKEN,
            ...$this->payload(),
            'charges' => [
                ['reason' => 'Delivery', 'reason_code' => 'DL', 'amount' => '15', 'tax_category' => 'S', 'tax_rate' => '15'],
                ['reason' => '', 'amount' => '', 'percent' => '', 'tax_category' => 'S', 'tax_rate' => '15'],
            ],
        ])->assertSessionHasNoErrors());

        $this->assertCount(1, $this->invoice()->charges);
    }

    /**
     * A credit note started from the invoice credits its charges, and its
     * lines' discounts and charges, as they were entered.
     */
    public function test_a_credit_note_carries_them(): void
    {
        $this->api([
            'lines' => [['description' => 'Chair', 'description_ar' => 'كرسي', 'quantity' => 1, 'unit_price' => 100, 'discount_amount' => '5.00', 'charges' => [['reason' => 'Packing', 'reason_code' => 'PC', 'amount' => '3.00']]]],
            'charges' => [['reason' => 'Service charge', 'reason_code' => 'ADR', 'percent' => '10']],
        ])->assertCreated();
        $invoice = $this->invoice();
        $invoice->forceFill(['status' => 'issued', 'icv' => 1, 'hash' => base64_encode('h')])->save();

        $this->portal()->get(route('portal.invoices.adjust', [$invoice->id, 'credit_note']));
        $fields = session()->getOldInput();

        // The code's Arabic beside the reason (FatooraConfig::CHARGE_REASONS_AR).
        $this->assertSame([['reason' => 'Packing / رسوم التغليف', 'reason_code' => 'PC', 'amount' => '3.00']], $fields['lines'][0]['charges']);
        $this->assertSame(['reason' => 'Service charge / رسوم الخدمات', 'reason_code' => 'ADR', 'percent' => '10', 'base_amount' => '98.00'], array_intersect_key($fields['charges'][0], array_flip(['reason', 'reason_code', 'percent', 'base_amount'])));

        $this->portal()->post('/portal/invoices', [...$fields, 'issue_date' => now()->toDateString(), 'adjustment_reason' => AdjustmentReason::values()[3]])
            ->assertSessionHasNoErrors();

        $note = Invoice::withoutTenantScope(fn () => Invoice::query()->where('document_type', 'credit_note')->sole());
        $this->assertSame((string) $invoice->total, (string) $note->total);
    }

    /** Frozen with the invoice, as its lines are. */
    public function test_an_issued_charge_cannot_change(): void
    {
        $this->api(['charges' => [['reason' => 'Delivery', 'reason_code' => 'DL', 'amount' => '25.00']]])->assertCreated();
        $invoice = $this->invoice();
        $invoice->forceFill(['status' => 'issued'])->save();

        $this->expectException(InvoiceNotEditableException::class);

        InvoiceCharge::query()->where('invoice_id', $invoice->id)->firstOrFail()->update(['amount' => '1.00']);
    }

    private function invoice(): Invoice
    {
        return Invoice::withoutTenantScope(fn () => Invoice::query()->with(['lines.charges', 'charges'])->where('document_type', 'invoice')->sole());
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'invoice_number' => 'INV-1',
            'type' => 'simplified',
            'issue_date' => now()->toDateString(),
            'lines' => [['description' => 'Item', 'description_ar' => 'صنف', 'quantity' => 1, 'unit_price' => 100]],
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

    private function portal(): static
    {
        return $this->actingAs($this->admin)->withSession(['portal_org_id' => $this->acme->id]);
    }
}
