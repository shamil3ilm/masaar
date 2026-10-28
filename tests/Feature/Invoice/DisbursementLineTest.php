<?php

declare(strict_types=1);

namespace Tests\Feature\Invoice;

use App\Domains\Auth\Models\User;
use App\Domains\Compliance\Countries\SaudiArabia\Presentation\PrintedTerms;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Invoice\Services\VatSummary;
use App\Domains\Organization\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * A disbursement: a cost paid in the customer's name and for its account,
 * passed on at cost, outside the value of the supply (GCC VAT Agreement Art
 * 26(6)(c)) - out of scope with VATEX-SA-OOS, never charged VAT, shown
 * apart on the invoice and in the reports.
 *
 * Worked example: a law firm bills 1,000.00 of legal services and the
 * 500.00 court fee it paid in the client's name. The invoice is 1,000.00 +
 * VAT 15% 150.00 on the services, and 500.00 out of scope: 1,650.00 in all.
 */
class DisbursementLineTest extends TestCase
{
    use RefreshDatabase;

    private Organization $firm;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->firm = Organization::create(['name' => 'Al Adl Law Firm', 'country' => 'SA', 'vat_number' => '399999999900003']);
        $this->admin = User::factory()->create();
        $this->admin->organizations()->attach($this->firm->id, ['role' => 'admin', 'status' => 'active']);
    }

    public function test_a_disbursement_carries_no_vat(): void
    {
        $data = $this->bill(true)->assertCreated()->json('data');
        $invoice = Invoice::withoutTenantScope(fn () => Invoice::query()->with('lines')->findOrFail($data['invoice']['id']));
        $fee = $invoice->lines[1];

        $this->assertSame(['1500.00', '150.00', '1650.00'], [(string) $invoice->subtotal, (string) $invoice->tax_amount, (string) $invoice->total]);
        $this->assertSame(['disbursement', 'O', 'VATEX-SA-OOS', '0.00'], [$fee->kind, $fee->tax_category, $fee->exempt_code, (string) $fee->tax_amount]);
        $this->assertStringContainsString('customer\'s name', (string) $fee->exempt_reason);
        $this->assertNull($invoice->lines[0]->kind);
    }

    public function test_the_conditions_are_confirmed_first(): void
    {
        $this->bill(false)->assertUnprocessable()->assertJsonValidationErrors('disbursements_confirmed', 'error.details');
    }

    public function test_it_is_shown_and_reported_apart(): void
    {
        $id = $this->bill(true)->json('data.invoice.id');
        $invoice = Invoice::withoutTenantScope(fn () => Invoice::query()->findOrFail($id));
        $invoice->update(['status' => 'issued', 'hash' => 'hash-1', 'icv' => 1, 'issued_at' => now()]);

        $terms = array_map(fn ($row): string => $row->label->en.': '.$row->value, PrintedTerms::of($invoice->fresh('lines')));
        $this->assertContains('Disbursements, outside the scope of VAT: 500.00 SAR', $terms);

        $summary = app(VatSummary::class)->for($this->firm->id, CarbonImmutable::today()->startOfMonth(), CarbonImmutable::today()->endOfMonth());
        $this->assertSame([1000.0, 150.0, 500.0, 500.0], [$summary['boxes'][1]['base'], $summary['boxes'][1]['vat'], $summary['out_of_scope'], $summary['disbursements']]);
    }

    private function bill(bool $confirmed): TestResponse
    {
        $token = JWTAuth::claims(['org_id' => $this->firm->id, 'role' => 'admin'])->fromUser($this->admin);

        return $this->withToken($token)->postJson('/api/invoices', [
            'type' => 'simplified',
            'issue_date' => now()->toDateString(),
            'prices_include_vat' => false,
            'disbursements_confirmed' => $confirmed,
            'lines' => [
                ['description' => 'Legal services', 'description_ar' => 'خدمات قانونية', 'quantity' => 1, 'unit_price' => '1000.00'],
                // Given as standard-rated: a disbursement is out of scope whatever it says.
                ['description' => 'Court fee paid for you', 'description_ar' => 'رسوم محكمة دُفعت عنك', 'quantity' => 1, 'unit_price' => '500.00', 'kind' => 'disbursement', 'tax_category' => 'S', 'tax_rate' => 15],
            ],
        ]);
    }
}
