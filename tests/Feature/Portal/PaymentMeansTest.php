<?php

declare(strict_types=1);

namespace Tests\Feature\Portal;

use App\Domains\Auth\Models\User;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * How a Saudi invoice is paid (BT-81).
 *
 * The portal form had no field for it, so every invoice written there said
 * cash (10) - a bank transfer, a card payment, all filed as cash. It offers
 * the codes ZATCA names, cash still first; the API takes any code ZATCA's
 * BR-KSA-16 accepts, which "exactly two characters" did not describe.
 */
class PaymentMeansTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'payment-means-token';

    private Organization $acme;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->acme = Organization::create(['name' => 'Acme', 'country' => 'SA']);
        $this->member = User::factory()->create();
        $this->member->organizations()->attach($this->acme->id, ['role' => 'admin', 'status' => 'active']);
    }

    public function test_the_form_offers_zatca_codes(): void
    {
        $page = $this->asRequest(fn () => $this->actingAs($this->member)->get('/portal/invoices/create')->assertOk());

        foreach (['10', '30', '42', '48', '1'] as $code) {
            $page->assertSee('<option value="'.$code.'"', false);
        }

        $page->assertSee('<option value="10" selected', false);
    }

    public function test_the_chosen_means_is_kept(): void
    {
        $this->asRequest(fn () => $this->actingAs($this->member)
            ->withSession(['_token' => self::TOKEN])
            ->post('/portal/invoices', [
                '_token' => self::TOKEN,
                'type' => 'simplified',
                'document_type' => 'invoice',
                'issue_date' => now()->toDateString(),
                'payment_means_code' => '48',
                'lines' => [['description' => 'Tea', 'description_ar' => 'شاي', 'quantity' => '1', 'unit_price' => '10']],
            ])->assertSessionHasNoErrors());

        $this->assertSame('48', Invoice::acrossTenants()->sole()->payment_means_code);
    }

    /** "1", an instrument not defined, is one ZATCA names. */
    public function test_a_one_digit_code_is_accepted(): void
    {
        $this->api('1')->assertCreated();
    }

    /** And one BR-KSA-16 would flag is refused before it is drafted. */
    public function test_an_unlisted_code_is_refused(): void
    {
        $this->api('99')->assertStatus(422)->assertJsonValidationErrorFor('payment_means_code', 'error.details');
    }

    private function api(string $code): TestResponse
    {
        $token = JWTAuth::claims(['org_id' => $this->acme->id, 'role' => 'admin'])->fromUser($this->member);

        return $this->withToken($token)->postJson('/api/invoices', [
            'invoice_number' => 'INV-1',
            'type' => 'simplified',
            'issue_date' => now()->toDateString(),
            'payment_means_code' => $code,
            'lines' => [['description' => 'Tea', 'description_ar' => 'شاي', 'quantity' => 1, 'unit_price' => 10]],
        ]);
    }
}
