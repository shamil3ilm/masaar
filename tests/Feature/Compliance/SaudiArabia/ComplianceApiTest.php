<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance\SaudiArabia;

use App\Domains\Auth\Models\User;
use App\Domains\Compliance\Countries\SaudiArabia\Models\InvoiceSubmission;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
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
                'warnings' => [],
                'rejections' => [],
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

    /**
     * A retry after a timeout that did issue gets what the first call got,
     * from the stored columns: nothing is signed again.
     */
    public function test_generate_retry_returns_stored_seal(): void
    {
        $invoice = $this->invoice($this->acme, ['status' => 'issued', 'icv' => 7, 'hash' => 'aGFzaA==', 'qr_code' => 'cXI=', 'signed_xml' => '<Invoice/>']);

        $this->withToken($this->token)
            ->postJson("/api/compliance/sa/generate/{$invoice->id}")
            ->assertOk()
            ->assertHeader('Idempotent-Replayed', 'true')
            ->assertJsonPath('data', ['hash' => 'aGFzaA==', 'qr_code' => 'cXI=', 'signed_xml' => '<Invoice/>']);

        $this->assertSame('aGFzaA==', $invoice->fresh()->hash);
    }

    /**
     * And a submit retry after ZATCA accepted it gets the acceptance, not
     * "must be issued", and nothing is sent again.
     */
    public function test_submit_retry_returns_stored_outcome(): void
    {
        Http::fake();
        $invoice = $this->invoice($this->acme, ['status' => 'accepted', 'icv' => 7, 'hash' => 'aGFzaA==']);
        $this->submission($invoice, [
            'state' => 'reported',
            'reporting_status' => 'REPORTED',
            'zatca_warnings' => [['code' => 'BR-KSA-08', 'message' => 'Advisory']],
        ]);

        $this->withToken($this->token)
            ->postJson("/api/compliance/sa/submit/{$invoice->id}")
            ->assertOk()
            ->assertHeader('Idempotent-Replayed', 'true')
            ->assertJsonPath('data.status', 'REPORTED')
            ->assertJsonCount(1, 'data.warnings');

        Http::assertNothingSent();
        $this->assertSame(1, InvoiceSubmission::withoutTenantScope(fn () => InvoiceSubmission::query()->count()));
    }

    public function test_submit_retry_returns_stored_rejection(): void
    {
        Http::fake();
        $invoice = $this->invoice($this->acme, ['status' => 'rejected', 'icv' => 7, 'hash' => 'aGFzaA==']);
        $this->submission($invoice, [
            'state' => 'rejected',
            'zatca_errors' => [['code' => 'BR-KSA-37', 'category' => 'XSD_SCHEMA_ERROR', 'message' => 'Refused']],
        ]);

        $this->withToken($this->token)
            ->postJson("/api/compliance/sa/submit/{$invoice->id}")
            ->assertStatus(422)
            ->assertJsonPath('error.message', 'Fatoora submission failed')
            ->assertJsonPath('error.details.0.code', 'BR-KSA-37');

        Http::assertNothingSent();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function submission(Invoice $invoice, array $attributes): void
    {
        InvoiceSubmission::withoutTenantScope(fn () => InvoiceSubmission::create([
            'invoice_id' => $invoice->id,
            'org_id' => $invoice->org_id,
            'submission_type' => 'reporting',
            ...$attributes,
        ]));
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
