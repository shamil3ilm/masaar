<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Domains\Auth\Models\User;
use App\Domains\Books\Models\InvoicePayment;
use App\Domains\Books\Models\Voucher;
use App\Domains\Invoice\Models\Contract;
use App\Domains\Invoice\Models\Customer;
use App\Domains\Invoice\Models\DeliveryNote;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Licensing\Models\License;
use App\Domains\Organization\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Fixtures\MakesInvoices;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * An Idempotency-Key on the writes that move money or draft an invoice and
 * keep no key of their own: a voucher sold or used, a contract made or its
 * retention released, a delivery recorded, a month's summary drafted. A
 * retry after a timeout answers with what the first request did; it does
 * not sell, pay, release or draft a second time.
 */
class IdempotentMoneyWritesTest extends TestCase
{
    use MakesInvoices;
    use RefreshDatabase;

    private Organization $seller;

    private Customer $customer;

    /** @var array<string, string> */
    private array $headers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-01 09:00:00', 'UTC'));
        $this->seller = Organization::create(['name' => 'Al Bina Contracting', 'country' => 'SA', 'vat_number' => '399999999900003']);
        $this->customer = new Customer([
            'org_id' => $this->seller->id, 'name' => 'Al Noor Real Estate', 'name_ar' => 'النور العقارية',
            'vat_number' => '311111111100003', 'street' => 'King Abdullah Road', 'building_number' => '7788',
            'district' => 'Al Malqa', 'city' => 'Riyadh', 'postal_code' => '13521', 'country_code' => 'SA',
        ]);
        $this->customer->save();

        $issued = License::createWithCredentials(['org_id' => $this->seller->id, 'organization_name' => 'Al Bina', 'contact_email' => 'erp@example.test', 'tier' => 'enterprise']);
        License::withoutGlobalScopes()->whereKey($issued['license']->id)->update(['status' => 'active', 'scopes' => json_encode(['invoice.read', 'invoice.submit'])]);
        $this->headers = ['X-API-Key' => $issued['api_key'], 'X-API-Secret' => $issued['api_secret']];
    }

    public function test_a_retried_voucher_is_sold_once(): void
    {
        $body = ['face_value' => '500', 'sold_on' => '2026-10-01', 'payment_method' => 'cash'];

        $first = $this->twice('/api/v1/vouchers', $body, 'voucher-1')[0];

        $this->assertSame(1, Voucher::acrossTenants()->count());
        $this->assertSame($first->json('data.voucher.code'), Voucher::acrossTenants()->value('code'));
    }

    public function test_a_retried_redemption_pays_once(): void
    {
        $this->postJson('/api/v1/vouchers', ['code' => 'GIFT-500', 'face_value' => '500', 'sold_on' => '2026-10-01', 'payment_method' => 'cash'], $this->headers)->assertCreated();
        $invoice = $this->makeInvoice($this->seller, 'INV-1', ['type' => 'simplified', 'document_type' => 'invoice', 'issue_date' => '2026-10-02', 'subtotal' => '300.00', 'tax_amount' => '45.00', 'total' => '345.00']);
        $invoice->update(['status' => 'issued', 'hash' => 'hash-INV-1', 'icv' => 1, 'issued_at' => now()]);

        [, $again] = $this->twice('/api/v1/vouchers/GIFT-500/redeem', ['invoice_number' => 'INV-1', 'amount' => '100.00'], 'redeem-1', 200);

        $again->assertJsonPath('data.voucher.balance', '400.00');
        $this->assertSame(1, InvoicePayment::query()->count());
        $this->getJson('/api/v1/vouchers/GIFT-500', $this->headers)->assertJsonPath('data.voucher.balance', '400.00');
    }

    public function test_a_retried_contract_is_made_once(): void
    {
        $this->twice('/api/v1/contracts', $this->contract(), 'contract-1');

        $this->assertSame(1, Contract::acrossTenants()->count());
    }

    public function test_a_retried_release_drafts_once(): void
    {
        $contract = $this->postJson('/api/v1/contracts', $this->contract(), $this->headers)->assertCreated()->json('data');
        $stage = $contract['statement']['stages'][0]['id'];
        $this->postJson("/api/v1/contracts/{$contract['contract']['id']}/stages/{$stage}/invoice", ['issue_date' => '2026-10-10'], $this->headers)->assertCreated();

        [$first, $again] = $this->twice("/api/v1/contracts/{$contract['contract']['id']}/release", ['amount' => '5000', 'issue_date' => '2026-10-20'], 'release-1');

        $this->assertSame($first->json('data.invoice.id'), $again->json('data.invoice.id'));
        $this->assertSame(2, Invoice::acrossTenants()->count(), 'the stage invoice and one release');
    }

    public function test_a_retried_delivery_is_recorded_once(): void
    {
        $this->twice('/api/v1/delivery-notes', $this->delivery(), 'note-1');

        $this->assertSame(1, DeliveryNote::acrossTenants()->count());
    }

    public function test_a_retried_summary_is_drafted_once(): void
    {
        $this->postJson('/api/v1/delivery-notes', $this->delivery(), $this->headers)->assertCreated();

        [$first, $again] = $this->twice('/api/v1/invoices/summary', ['customer_id' => $this->customer->id, 'from' => '2026-10-01', 'to' => '2026-10-31', 'issue_date' => '2026-11-01'], 'summary-1');

        $this->assertSame($first->json('data.invoice.id'), $again->json('data.invoice.id'));
        $this->assertSame(1, Invoice::acrossTenants()->count());
    }

    /** The bearer-token routes take the key as the licence's do. */
    public function test_the_session_routes_take_it(): void
    {
        $admin = User::factory()->create();
        $admin->organizations()->attach($this->seller->id, ['role' => 'admin', 'status' => 'active']);
        $this->headers = ['Authorization' => 'Bearer '.JWTAuth::claims(['org_id' => $this->seller->id, 'role' => 'admin'])->fromUser($admin)];

        $this->twice('/api/vouchers', ['face_value' => '500', 'sold_on' => '2026-10-01', 'payment_method' => 'cash'], 'voucher-2');

        $this->assertSame(1, Voucher::acrossTenants()->count());
    }

    /**
     * The request sent, and sent again under the same key: the first
     * answer, replayed.
     *
     * @param  array<string, mixed>  $body
     * @return array{TestResponse, TestResponse}
     */
    private function twice(string $path, array $body, string $key, int $status = 201): array
    {
        $headers = [...$this->headers, 'Idempotency-Key' => $key];

        $first = $this->postJson($path, $body, $headers);
        $this->assertSame($status, $first->status(), (string) $first->getContent());
        $first->assertHeaderMissing('Idempotent-Replayed');

        $again = $this->postJson($path, $body, $headers)->assertStatus($status)->assertHeader('Idempotent-Replayed', 'true');
        $this->assertSame($first->json('data'), $again->json('data'));

        return [$first, $again];
    }

    /** @return array<string, mixed> */
    private function contract(): array
    {
        return [
            'customer_id' => $this->customer->id, 'reference' => 'C-2026-07', 'title' => 'Villa construction', 'title_ar' => 'إنشاء فيلا',
            'value' => '1000000', 'retention_percent' => '10',
            'stages' => [['name' => 'Foundations', 'name_ar' => 'الأساسات', 'percent' => '20'], ['name' => 'Structure', 'name_ar' => 'الهيكل', 'percent' => '80']],
        ];
    }

    /** @return array<string, mixed> */
    private function delivery(): array
    {
        return ['customer_id' => $this->customer->id, 'delivered_on' => '2026-10-05', 'lines' => [['description' => 'Cement', 'description_ar' => 'إسمنت', 'quantity' => 3, 'unit_price' => '200.00']]];
    }
}
