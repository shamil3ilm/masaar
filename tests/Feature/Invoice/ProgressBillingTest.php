<?php

declare(strict_types=1);

namespace Tests\Feature\Invoice;

use App\Domains\Auth\Models\User;
use App\Domains\Invoice\Models\Customer;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * A contract billed in stages, with retention withheld and released later.
 *
 * Worked example: contract C-2026-07, a villa, 1,000,000.00 before VAT,
 * retention 10%, in three stages - foundations 20%, structure 30%,
 * finishing 50%. Foundations is invoiced for 200,000 less 20,000 retained
 * = 180,000.00 + VAT 15% 27,000.00 = 207,000.00. After structure too, the
 * statement shows 500,000.00 billed, 50,000.00 retained and held, 500,000.00
 * left to bill, and the tax on the retention due twelve months after the
 * first stage if it is still held then (KSA IR Art 20(3)). Releasing it is
 * its own invoice: 50,000.00 + 7,500.00 = 57,500.00.
 */
class ProgressBillingTest extends TestCase
{
    use RefreshDatabase;

    private Organization $seller;

    private User $admin;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seller = Organization::create(['name' => 'Al Bina Contracting', 'country' => 'SA', 'vat_number' => '399999999900003']);
        $this->admin = User::factory()->create();
        $this->admin->organizations()->attach($this->seller->id, ['role' => 'admin', 'status' => 'active']);
        $this->customer = new Customer([
            'org_id' => $this->seller->id, 'name' => 'Al Noor Real Estate', 'name_ar' => 'النور العقارية',
            'vat_number' => '311111111100003', 'street' => 'King Abdullah Road', 'building_number' => '7788',
            'district' => 'Al Malqa', 'city' => 'Riyadh', 'postal_code' => '13521', 'country_code' => 'SA',
        ]);
        $this->customer->save();
    }

    public function test_a_stage_is_billed_less_retention(): void
    {
        $contract = $this->contract()->assertCreated()->json('data');
        $this->assertSame(['200000.00', '300000.00', '500000.00'], array_column($contract['statement']['stages'], 'amount'));

        $data = $this->invoiceStage($contract, 0, '2026-03-10')->assertCreated()->json('data');
        $invoice = $this->invoice($data['invoice']['id']);

        $this->assertSame(['180000.00', '27000.00', '207000.00'], [(string) $invoice->subtotal, (string) $invoice->tax_amount, (string) $invoice->total]);
        $this->assertSame('C-2026-07', $invoice->contract_reference);
        $this->assertSame('Stage 1: Foundations, contract C-2026-07', $invoice->lines[0]->description);
        $this->assertSame('20000.00', $data['statement']['stages'][0]['retention']);
    }

    public function test_the_statement_shows_retention_held(): void
    {
        $contract = $this->contract()->json('data');
        $this->invoiceStage($contract, 0, '2026-03-10')->assertCreated();
        $statement = $this->invoiceStage($contract, 1, '2026-06-10')->assertCreated()->json('data.statement');

        $this->assertSame(
            ['1000000.00', '500000.00', '50000.00', '0.00', '50000.00', '500000.00', '450000.00', '2027-03-10'],
            [$statement['value'], $statement['billed'], $statement['retained'], $statement['released'], $statement['retention_held'], $statement['remaining'], $statement['invoiced'], $statement['retention_due_by']],
        );
    }

    public function test_released_retention_is_its_own_invoice(): void
    {
        $contract = $this->contract()->json('data');
        $this->invoiceStage($contract, 0, '2026-03-10')->assertCreated();
        $this->invoiceStage($contract, 1, '2026-06-10')->assertCreated();

        $this->api()->postJson("/api/contracts/{$contract['contract']['id']}/release", ['amount' => '50000.01', 'issue_date' => '2026-12-01'])
            ->assertUnprocessable()->assertJsonValidationErrors('amount', 'error.details');
        $data = $this->api()->postJson("/api/contracts/{$contract['contract']['id']}/release", ['issue_date' => '2026-12-01'])->assertCreated()->json('data');

        $this->assertSame(['50000.00', '7500.00', '57500.00'], array_map('strval', [$data['invoice']['subtotal'], $data['invoice']['tax_amount'], $data['invoice']['total']]));
        $this->assertSame(['50000.00', '0.00', null], [$data['statement']['released'], $data['statement']['retention_held'], $data['statement']['retention_due_by']]);
    }

    /**
     * N2-5: a credit note on a stage's invoice takes back what it credits.
     * Foundations credited in full and structure by half leave 150,000.00
     * billed and 15,000.00 retained, so no more than that can be released.
     */
    public function test_credited_stages_leave_the_statement(): void
    {
        $contract = $this->contract()->json('data');
        $foundations = $this->invoiceStage($contract, 0, '2026-03-10')->json('data.invoice.id');
        $structure = $this->invoiceStage($contract, 1, '2026-06-10')->json('data.invoice.id');
        $this->creditNote($foundations, '180000.00');
        $this->creditNote($structure, '135000.00');
        $this->creditNote($structure, '999.00', 'draft');

        $statement = $this->api()->getJson("/api/contracts/{$contract['contract']['id']}")->assertOk()->json('data.statement');

        $this->assertSame(
            ['150000.00', '15000.00', '15000.00', '850000.00', '135000.00'],
            [$statement['billed'], $statement['retained'], $statement['retention_held'], $statement['remaining'], $statement['invoiced']],
        );
        $this->api()->postJson("/api/contracts/{$contract['contract']['id']}/release", ['amount' => '15000.01', 'issue_date' => '2026-12-01'])
            ->assertUnprocessable()->assertJsonValidationErrors('amount', 'error.details');
    }

    public function test_a_stage_is_invoiced_once(): void
    {
        $contract = $this->contract()->json('data');
        $this->invoiceStage($contract, 0, '2026-03-10')->assertCreated();

        $this->invoiceStage($contract, 0, '2026-03-11')->assertUnprocessable()->assertJsonValidationErrors('stage', 'error.details');
    }

    public function test_stages_never_exceed_the_value(): void
    {
        $this->contract(['stages' => [['name' => 'All', 'amount' => '600000'], ['name' => 'More', 'amount' => '400000.01']]])
            ->assertUnprocessable()->assertJsonValidationErrors('stages', 'error.details');
    }

    public function test_the_portal_shows_the_statement(): void
    {
        $contract = $this->contract()->json('data');
        $this->invoiceStage($contract, 0, '2026-03-10')->assertCreated();

        $this->actingAs($this->admin)->get(route('portal.contracts.index'))->assertOk()->assertSee('C-2026-07');
        $this->actingAs($this->admin)->get(route('portal.contracts.show', $contract['contract']['id']))
            ->assertOk()->assertSee('Retention held')->assertSee('Invoice this stage');
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function contract(array $overrides = []): TestResponse
    {
        return $this->api()->postJson('/api/contracts', [
            'customer_id' => $this->customer->id, 'reference' => 'C-2026-07', 'title' => 'Villa construction', 'title_ar' => 'إنشاء فيلا',
            'value' => '1000000', 'retention_percent' => '10',
            'stages' => [
                ['name' => 'Foundations', 'name_ar' => 'الأساسات', 'percent' => '20'],
                ['name' => 'Structure', 'name_ar' => 'الهيكل', 'percent' => '30'],
                ['name' => 'Finishing', 'name_ar' => 'التشطيبات', 'percent' => '50'],
            ],
            ...$overrides,
        ]);
    }

    /**
     * @param  array<string, mixed>  $contract
     */
    private function invoiceStage(array $contract, int $index, string $date): TestResponse
    {
        $stage = $contract['statement']['stages'][$index]['id'];

        return $this->api()->postJson("/api/contracts/{$contract['contract']['id']}/stages/{$stage}/invoice", ['issue_date' => $date]);
    }

    /** A credit note naming the stage invoice by its id, for $net before VAT. */
    private function creditNote(string $invoiceId, string $net, string $status = 'issued'): void
    {
        $note = new Invoice;
        $note->forceFill([
            'org_id' => $this->seller->id, 'type' => 'standard', 'document_type' => 'credit_note', 'status' => $status,
            'invoice_number' => 'CN-'.uniqid(), 'billing_ref' => $invoiceId, 'adjustment_reason' => 'Stage cancelled',
            'issue_date' => '2026-07-01', 'currency' => 'SAR', 'buyer_name' => 'Al Noor Real Estate',
            'subtotal' => $net, 'tax_amount' => bcmul($net, '0.15', 2), 'total' => bcmul($net, '1.15', 2),
        ])->save();
    }

    private function invoice(string $id): Invoice
    {
        return Invoice::acrossTenants()->with('lines')->findOrFail($id);
    }

    private function api(): static
    {
        return $this->withToken(JWTAuth::claims(['org_id' => $this->seller->id, 'role' => 'admin'])->fromUser($this->admin));
    }
}
