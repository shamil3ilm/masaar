<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Domains\Auth\Models\User;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * The Fatoora endpoints that read an invoice before acting on it.
 *
 * Each looks the invoice up inside the caller's organization, so another
 * tenant's id answers 404 before any compliance work starts.
 */
class ComplianceApiTest extends TestCase
{
    use RefreshDatabase;

    private Organization $acme;

    private Organization $rival;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->acme = Organization::create(['name' => 'Acme', 'country' => 'SA']);
        $this->rival = Organization::create(['name' => 'Rival', 'country' => 'SA']);

        $user = User::factory()->create();
        $user->organizations()->attach($this->acme->id, ['role' => 'admin', 'status' => 'active']);

        $this->token = JWTAuth::claims(['org_id' => $this->acme->id, 'role' => 'admin'])->fromUser($user);
    }

    public function test_status_reports_own_invoice(): void
    {
        $invoice = $this->invoice($this->acme, ['hash' => 'aGFzaA==', 'qr_code' => 'cXI=']);

        $this->withToken($this->token)
            ->getJson("/api/compliance/sa/status/{$invoice->id}")
            ->assertOk()
            ->assertExactJson(['success' => true, 'data' => [
                'invoice_id' => $invoice->id,
                'status' => 'draft',
                'hash' => 'aGFzaA==',
                'qr_code' => 'cXI=',
                'zatca_response' => null,
            ]]);
    }

    public function test_rival_invoice_not_found(): void
    {
        $theirs = $this->invoice($this->rival);

        $this->withToken($this->token)->getJson("/api/compliance/sa/status/{$theirs->id}")->assertNotFound();
        $this->withToken($this->token)->postJson("/api/compliance/sa/submit/{$theirs->id}")->assertNotFound();
    }

    public function test_draft_submission_refused(): void
    {
        $invoice = $this->invoice($this->acme);

        $this->withToken($this->token)
            ->postJson("/api/compliance/sa/submit/{$invoice->id}")
            ->assertStatus(422)
            ->assertJsonPath('error.message', 'Invoice must be issued before submission');
    }

    private function invoice(Organization $organization, array $attributes = []): Invoice
    {
        $invoice = (new Invoice)->forceFill([
            'org_id' => $organization->id,
            'invoice_number' => 'INV-'.uniqid(),
            'type' => 'standard',
            'status' => 'draft',
            'issue_date' => now()->toDateString(),
            'currency' => 'SAR',
            'buyer_name' => 'Buyer',
            ...$attributes,
        ]);

        $invoice->save();

        return $invoice;
    }
}
