<?php

declare(strict_types=1);

namespace Tests\Feature\Books;

use App\Domains\Auth\Models\User;
use App\Domains\Books\Models\DeemedSupply;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Deemed (nominal) supplies in Saudi Arabia, KSA VAT Implementing
 * Regulations Art 15.
 *
 * Worked examples, in one calendar year:
 *   a 150.00 gift to Ahmed is within SAR 200 to one recipient: relieved;
 *   a second gift of 100.00 takes him to 250.00: taxed, 100.00 + VAT 15.00,
 *   on a kept simplified invoice flagged as a nominal supply (KSA-2
 *   position 4);
 *   a 3,000.00 laptop taken home, its input tax half deducted: taxed on
 *   1,500.00, VAT 225.00 (Art 15(8));
 *   stock bought without deducting its input tax: not a deemed supply
 *   (Art 15(9)).
 */
class DeemedSupplyTest extends TestCase
{
    use RefreshDatabase;

    private Organization $shop;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Organization::create(['name' => 'Riyadh Gifts', 'country' => 'SA', 'vat_number' => '399999999900003']);
        $this->admin = User::factory()->create();
        $this->admin->organizations()->attach($this->shop->id, ['role' => 'admin', 'status' => 'active']);
    }

    public function test_small_gifts_are_relieved(): void
    {
        $this->record(['kind' => 'gift', 'recipient' => 'Ahmed', 'value' => '150'])->assertRedirect(route('portal.books.deemed-supplies'));

        $this->assertSame([DeemedSupply::RELIEVED, '0.00', null], [$this->last()->outcome, (string) $this->last()->tax_amount, $this->last()->invoice_id]);
    }

    public function test_gifts_past_the_allowance_are_taxed(): void
    {
        $this->record(['kind' => 'gift', 'recipient' => 'Ahmed', 'value' => '150']);
        $this->record(['kind' => 'gift', 'recipient' => 'ahmed ', 'value' => '100']);

        $record = $this->last();
        $invoice = Invoice::withoutTenantScope(fn () => Invoice::query()->with('lines')->findOrFail($record->invoice_id));

        $this->assertSame([DeemedSupply::TAXED, '100.00', '15.00'], [$record->outcome, (string) $record->taxable_value, (string) $record->tax_amount]);
        $this->assertSame(['simplified', true, '115.00'], [$invoice->type->value, $invoice->is_nominal, (string) $invoice->total]);
        $this->assertSame('Deemed supply: Gift basket', $invoice->lines[0]->description);
    }

    public function test_partial_deduction_scales_the_value(): void
    {
        $this->record(['kind' => 'own_use', 'description' => 'Laptop taken home', 'value' => '3000', 'input_tax_percent' => '50']);

        $this->assertSame(['1500.00', '225.00'], [(string) $this->last()->taxable_value, (string) $this->last()->tax_amount]);
    }

    public function test_no_deduction_means_no_supply(): void
    {
        $this->record(['kind' => 'own_use', 'value' => '800', 'input_tax_percent' => '0']);

        $this->assertSame([DeemedSupply::NOT_DEEMED, null], [$this->last()->outcome, $this->last()->invoice_id]);
        $this->actingAs($this->admin)->get(route('portal.books.deemed-supplies'))->assertOk()->assertSee('Not a deemed supply');
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    private function record(array $fields): TestResponse
    {
        return $this->actingAs($this->admin)->post(route('portal.books.deemed-supplies.store'), [
            'description' => 'Gift basket', 'description_ar' => 'سلة هدايا', 'supplied_on' => now()->toDateString(), ...$fields,
        ]);
    }

    private function last(): DeemedSupply
    {
        return DeemedSupply::withoutTenantScope(fn () => DeemedSupply::query()->orderByDesc('id')->firstOrFail());
    }
}
