<?php

declare(strict_types=1);

namespace Tests\Feature\Invoice;

use App\Domains\Auth\Models\User;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Invoice\Services\InvoiceTotals;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * Amounts the invoice can actually carry, decided before it is drafted.
 *
 * Each of these was accepted, drafted, issued and chained, and then refused
 * by the authority - after the counter and the hash were spent, so the only
 * repair was a credit note.
 */
class AmountRuleTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $acme = Organization::create(['name' => 'Acme', 'country' => 'SA']);

        $user = User::factory()->create();
        $user->organizations()->attach($acme->id, ['role' => 'admin', 'status' => 'active']);

        $this->token = JWTAuth::claims(['org_id' => $acme->id, 'role' => 'admin'])->fromUser($user);
    }

    /**
     * A price is stored to two places and a quantity to three. Totals were
     * computed from what was sent, the document from what was stored: 1000
     * at 1.005 totalled 1005.00 and drew a line of 1010.00.
     */
    public function test_finer_than_stored_is_refused(): void
    {
        $this->create(['lines' => [['description' => 'Fuel', 'description_ar' => 'وقود', 'quantity' => 1000, 'unit_price' => 1.005]]])
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['details' => ['lines.0.unit_price']]]);

        $this->create(['lines' => [['description' => 'Gold', 'description_ar' => 'ذهب', 'quantity' => 1.0005, 'unit_price' => 10]]])
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['details' => ['lines.0.quantity']]]);
    }

    public function test_stored_precision_is_accepted(): void
    {
        $this->create(['lines' => [['description' => 'Gold', 'description_ar' => 'ذهب', 'quantity' => 1.125, 'unit_price' => 245.75]]])
            ->assertCreated();
    }

    /**
     * A discount larger than the lines made a negative invoice: 100 less 200
     * was issued with a total of -115.00.
     */
    public function test_a_discount_cannot_exceed_the_lines(): void
    {
        $this->create(['discount_amount' => 200])
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['details' => ['discount_amount']]]);
    }

    /**
     * Currency codes are upper case on the document. 'sar' was stored as
     * typed, escaped the foreign-currency rule, and went out as 'sar'.
     */
    public function test_the_currency_is_upper_cased(): void
    {
        $id = $this->create(['currency' => 'sar'])->assertCreated()->json('data.invoice.id');

        $this->assertSame('SAR', Invoice::withoutTenantScope(fn () => Invoice::find($id))->currency);
    }

    public function test_lower_case_foreign_needs_a_rate(): void
    {
        $this->create(['currency' => 'usd'])
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['details' => ['exchange_rate']]]);
    }

    /**
     * The invoice's VAT is the sum it states for its lines. Category VAT was
     * rounded in floating point, so 4.10 at 15% - a line VAT of 0.62 - made an
     * invoice VAT of 0.61.
     */
    public function test_vat_agrees_with_its_lines(): void
    {
        $totals = InvoiceTotals::of([['quantity' => '1', 'unit_price' => '4.10', 'tax_rate' => '15']], '0');

        $this->assertSame('0.62', $totals->tax);
        $this->assertSame('4.72', $totals->total);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function create(array $overrides): TestResponse
    {
        return $this->withToken($this->token)->postJson('/api/invoices', [
            'invoice_number' => 'INV-'.uniqid(),
            'type' => 'simplified',
            'issue_date' => now()->toDateString(),
            'buyer_name' => 'Buyer',
            'buyer_name_ar' => 'المشتري',
            'lines' => [['description' => 'Item', 'description_ar' => 'صنف', 'quantity' => 1, 'unit_price' => 100]],
            ...$overrides,
        ]);
    }
}
