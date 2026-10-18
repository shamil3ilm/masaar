<?php

declare(strict_types=1);

namespace Tests\Feature\Invoice;

use App\Domains\Auth\Models\User;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Compliance\UnitedArabEmirates\PintInvoices;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * A code outside the list the specification names is refused, and the
 * refusal says which codes are taken, so an integrator can correct it from
 * the answer alone (CodeListConformanceTest holds the lists to the official
 * artefacts).
 */
class CodeListRefusalTest extends TestCase
{
    use PintInvoices;
    use RefreshDatabase;

    public function test_saudi_payment_means_name_the_codes(): void
    {
        $this->saudi(['payment_means_code' => '99'])
            ->assertStatus(422)
            ->assertJsonPath('error.details.payment_means_code.0', 'The payment means is a UNTDID 4461 (D.16B) code ZATCA accepts: 1-68, 70, 74-78, 91-97, ZZZ (BR-KSA-16). For example 10 cash, 30 credit transfer, 42 bank account, 48 bank card, 1 not defined.');

        $this->saudi(['payment_means_code' => 'ZZZ', 'invoice_number' => 'CL-2'])->assertCreated();
    }

    /** BR-KSA-CL-01 is ISO 4217:2005: a code ISO added later is not ZATCA's yet. */
    public function test_saudi_currencies_are_zatcas_list(): void
    {
        $response = $this->saudi(['currency' => 'VES', 'exchange_rate' => '0.1'])->assertStatus(422);

        $this->assertStringContainsString('(BR-KSA-CL-01)', (string) $response->json('error.details.currency.0'));
        $this->assertStringContainsString('VEF', (string) $response->json('error.details.currency.0'));
    }

    public function test_uae_payment_means_follow_pint(): void
    {
        $this->uae(['payment_means_code' => '48'])
            ->assertStatus(422)
            ->assertJsonPath('error.details.payment_means_code.0', "The payment means is a code of PINT AE's list of UNCL 4461 (D.16B) codes used in the UAE: 1, 10, 20, 21, 30, 49, 54, 55, 68 (IBR-CL-16). For example 10 cash, 30 credit transfer, 54 credit card, 55 debit card.");

        $this->uae(['payment_means_code' => '54'])->assertCreated();
    }

    public function test_uae_currencies_are_pint_aes_list(): void
    {
        $this->uae(['currency' => 'XXX', 'exchange_rate' => '1'])->assertStatus(422)->assertJsonValidationErrorFor('currency', 'error.details');
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function saudi(array $overrides): TestResponse
    {
        $organization = Organization::query()->where('country', 'SA')->first() ?? Organization::create(['name' => 'Acme', 'country' => 'SA']);

        return $this->as($organization)->postJson('/api/invoices', [
            'invoice_number' => 'CL-1',
            'type' => 'simplified',
            'issue_date' => now()->toDateString(),
            'lines' => [['description' => 'Item', 'quantity' => 1, 'unit_price' => 100]],
            ...$overrides,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function uae(array $overrides): TestResponse
    {
        config(['peppol.countries.AE.enabled' => true]);
        $organization = Organization::query()->where('country', 'AE')->first() ?? $this->uaeOrganization();

        return $this->as($organization)->postJson('/api/compliance/ae/invoices', [
            'document_type' => 'invoice',
            'document_category' => 'tax',
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'currency' => 'AED',
            'buyer_name' => 'Noor Electronics LLC',
            'buyer_vat_number' => '100765432100003',
            'buyer_address' => ['street' => 'Khalifa Street', 'city' => 'Abu Dhabi', 'country_code' => 'AE'],
            'buyer_endpoint' => '0235:1007654321',
            'buyer_subdivision' => 'AUH',
            'transaction_flags' => '00000000',
            'lines' => [['item_name' => 'Consulting', 'quantity' => 1, 'unit_price' => 500, 'tax_category' => 'S', 'tax_rate' => 5]],
            ...$overrides,
        ]);
    }

    private function as(Organization $organization): static
    {
        $user = User::factory()->create();
        $user->organizations()->attach($organization->id, ['role' => 'admin', 'status' => 'active']);

        return $this->withToken(JWTAuth::claims(['org_id' => $organization->id, 'role' => 'admin'])->fromUser($user));
    }
}
