<?php

declare(strict_types=1);

namespace Tests\Feature\Books;

use App\Domains\Auth\Models\User;
use App\Domains\Books\Models\InvoicePayment;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Fixtures\MakesInvoices;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * A voucher with a face value: selling it is not a supply (KSA VAT
 * Implementing Regulations Art 19(1); UAE Decree-Law Art 7(1)), so no tax
 * invoice and no VAT; using it pays the invoice of what it buys, which
 * carries the VAT.
 *
 * Worked example: a 500.00 gift voucher sold for cash makes no invoice. It
 * pays 345.00 of an invoice of 300.00 + VAT 45.00, leaving 155.00, which
 * then pays part of an invoice of 230.00.
 */
class VoucherTest extends TestCase
{
    use MakesInvoices;
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

    public function test_a_sale_makes_no_invoice(): void
    {
        $data = $this->sell(['code' => 'GIFT-500'])->assertCreated()->json('data.voucher');

        $this->assertSame(['GIFT-500', '500.00', '500.00', 'active', 'SAR'], [$data['code'], $data['face_value'], $data['balance'], $data['status'], $data['currency']]);
        $this->assertSame(0, Invoice::acrossTenants()->count());
    }

    public function test_using_it_pays_the_invoice(): void
    {
        $this->sell(['code' => 'GIFT-500'])->assertCreated();
        $first = $this->issued('INV-1', '300.00', '45.00', '345.00');
        $second = $this->issued('INV-2', '200.00', '30.00', '230.00');

        $this->api()->postJson('/api/vouchers/GIFT-500/redeem', ['invoice_number' => 'INV-1', 'amount' => '345.00'])->assertOk()->assertJsonPath('data.voucher.balance', '155.00');
        $this->api()->postJson('/api/vouchers/GIFT-500/redeem', ['invoice_id' => $second->id])->assertOk()->assertJsonPath('data.voucher.status', 'used');

        $payments = InvoicePayment::query()->orderBy('amount')->get();
        $this->assertSame([['155.00', 'voucher', 'GIFT-500'], ['345.00', 'voucher', 'GIFT-500']], $payments->map(fn ($p): array => [(string) $p->amount, $p->method->value, $p->reference])->all());
        $this->assertSame([$second->id, $first->id], $payments->pluck('invoice_id')->all());
    }

    public function test_a_voucher_never_pays_more(): void
    {
        $this->sell(['code' => 'GIFT-500', 'face_value' => '100'])->assertCreated();
        $this->issued('INV-1', '300.00', '45.00', '345.00');

        $this->api()->postJson('/api/vouchers/GIFT-500/redeem', ['invoice_number' => 'INV-1', 'amount' => '100.01'])
            ->assertUnprocessable()->assertJsonValidationErrors('amount', 'error.details');
    }

    public function test_an_expired_voucher_is_refused(): void
    {
        $this->sell(['code' => 'OLD-1', 'sold_on' => '2026-01-01', 'expires_on' => '2026-06-30'])->assertCreated();
        $this->issued('INV-1', '300.00', '45.00', '345.00');

        $this->api()->postJson('/api/vouchers/OLD-1/redeem', ['invoice_number' => 'INV-1', 'redeemed_on' => '2026-07-01'])
            ->assertUnprocessable()->assertJsonValidationErrors('amount', 'error.details');
    }

    public function test_it_sells_at_face_value(): void
    {
        $this->sell(['sold_for' => '520'])->assertUnprocessable()->assertJsonValidationErrors('face_value', 'error.details');
        $this->sell(['sold_for' => '450'])->assertUnprocessable();
    }

    public function test_the_portal_lists_vouchers(): void
    {
        $this->sell(['code' => 'GIFT-500'])->assertCreated();

        $this->actingAs($this->admin)->get(route('portal.books.vouchers'))->assertOk()->assertSee('GIFT-500');
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function sell(array $overrides = []): TestResponse
    {
        return $this->api()->postJson('/api/vouchers', ['face_value' => '500', 'sold_on' => '2026-10-01', 'payment_method' => 'cash', ...$overrides]);
    }

    private function issued(string $number, string $subtotal, string $tax, string $total): Invoice
    {
        $invoice = $this->makeInvoice($this->shop, $number, ['type' => 'simplified', 'document_type' => 'invoice', 'issue_date' => '2026-10-02', 'subtotal' => $subtotal, 'tax_amount' => $tax, 'total' => $total]);
        $invoice->update(['status' => 'issued', 'hash' => 'hash-'.$number, 'icv' => (int) substr($number, 4), 'issued_at' => now()]);

        return $invoice;
    }

    private function api(): static
    {
        return $this->withToken(JWTAuth::claims(['org_id' => $this->shop->id, 'role' => 'admin'])->fromUser($this->admin));
    }
}
