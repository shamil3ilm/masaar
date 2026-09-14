<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Domains\Auth\Models\User;
use App\Domains\Compliance\Fatoora\Config\FatooraConfig;
use App\Domains\Compliance\Fatoora\Services\CredentialStore;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The six compliance documents are a hash chain, and ZATCA checks where it
 * starts.
 *
 * The first document's PIH is the base64 of SHA-256("0") written as hex text,
 * which is FatooraConfig::DEFAULT_FIRST_INVOICE_PIH. The onboarding endpoints
 * encoded the raw digest instead, so the chain they submitted began from a
 * hash the authority does not expect.
 */
class OnboardingComplianceCheckTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_chain_starts_at_zatcas_hash(): void
    {
        Storage::fake('creds');
        config(['fatoora.signing.disk' => 'creds']);

        $organization = Organization::create([
            'name' => 'Acme Trading',
            'country' => 'SA',
            'vat_number' => '300000000000003',
            'street' => 'King Fahd Road',
            'building_number' => '1234',
            'city' => 'Riyadh',
            'postal_code' => '12345',
        ]);

        $user = User::factory()->create(['email' => 'admin@masaar.test']);
        $user->organizations()->attach($organization->id, ['role' => 'admin', 'status' => 'active']);

        app(CredentialStore::class)->put($organization->id, null, CredentialStore::CCSID, [
            'ccsid' => 'the-ccsid',
            'secret' => 'the-secret',
            'requestId' => '1234567890',
        ]);

        Http::fake(['*/compliance/invoices' => Http::response(['validationResults' => ['status' => 'PASS']])]);

        $token = $this->postJson('/api/auth/login', [
            'email' => 'admin@masaar.test',
            'password' => 'password',
        ])->json('data.token.access_token');

        $this->withToken($token)->postJson('/api/compliance/onboarding/compliance-check');

        $sent = Http::recorded(fn ($request) => str_ends_with($request->url(), '/compliance/invoices'));

        $this->assertCount(6, $sent, 'The six compliance documents were not submitted.');
        $this->assertStringContainsString(
            FatooraConfig::DEFAULT_FIRST_INVOICE_PIH,
            (string) base64_decode($sent[0][0]['invoice']),
            'The first document does not start the chain from ZATCA\'s initial hash.'
        );
    }
}
