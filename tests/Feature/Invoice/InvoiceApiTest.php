<?php

declare(strict_types=1);

namespace Tests\Feature\Invoice;

use App\Domains\Audit\Services\AuditService;
use App\Domains\Auth\Models\User;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * Listing, reading, revising and discarding invoices over the tenant API.
 *
 * Only a draft may change. Every change is audited, and the change and its
 * audit entry are one write: an edit with no record of it is exactly what an
 * auditor cannot accept.
 */
class InvoiceApiTest extends TestCase
{
    use RefreshDatabase;

    private Organization $acme;

    private Organization $rival;

    private string $token;

    private int $number = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->acme = Organization::create(['name' => 'Acme', 'country' => 'SA']);
        $this->rival = Organization::create(['name' => 'Rival', 'country' => 'SA']);

        $user = User::factory()->create();
        $user->organizations()->attach($this->acme->id, ['role' => 'admin', 'status' => 'active']);

        $this->token = JWTAuth::claims(['org_id' => $this->acme->id, 'role' => 'admin'])->fromUser($user);
    }

    public function test_index_filters_status_and_pages(): void
    {
        $this->invoice($this->acme);
        $this->invoice($this->acme);
        $this->invoice($this->acme, ['status' => 'issued']);
        $this->invoice($this->rival);

        $this->api()->getJson('/api/invoices?status=draft&per_page=1')
            ->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.per_page', 1)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.org_id', $this->acme->id);

        $this->api()->getJson('/api/invoices')
            ->assertOk()
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.per_page', 15);
    }

    public function test_show_includes_lines(): void
    {
        $invoice = $this->invoice($this->acme);
        $invoice->lines()->create([
            'description' => 'Item',
            'quantity' => '1',
            'unit_code' => 'PCE',
            'unit_price' => '100.00',
            'tax_rate' => '15.00',
            'tax_amount' => '15.00',
            'tax_category' => 'S',
            'line_total' => '100.00',
        ]);

        $this->api()->getJson("/api/invoices/{$invoice->id}")
            ->assertOk()
            ->assertJsonPath('data.invoice.id', $invoice->id)
            ->assertJsonCount(1, 'data.invoice.lines');
    }

    public function test_update_revises_draft_and_audits(): void
    {
        $invoice = $this->invoice($this->acme);

        $this->api()->putJson("/api/invoices/{$invoice->id}", $this->payload(['buyer_name' => 'Renamed Buyer', 'notes' => 'Revised']))
            ->assertOk()
            ->assertJsonPath('message', 'Invoice updated')
            ->assertJsonPath('data.invoice.buyer_name', 'Renamed Buyer')
            ->assertJsonPath('data.invoice.notes', 'Revised')
            ->assertJsonStructure(['data' => ['invoice' => ['lines']]]);

        $this->assertDatabaseHas('audit_logs', ['action' => 'Invoice.updated', 'entity_id' => $invoice->id]);
    }

    public function test_issued_invoice_not_editable(): void
    {
        $invoice = $this->invoice($this->acme, ['status' => 'issued']);

        $this->api()->putJson("/api/invoices/{$invoice->id}", $this->payload(['buyer_name' => 'Renamed Buyer']))
            ->assertStatus(422)
            ->assertJsonPath('error.message', 'Invoice cannot be edited after issuance');

        $this->assertSame('Buyer', $this->reload($invoice)->buyer_name);
    }

    public function test_issued_invoice_not_deletable(): void
    {
        $invoice = $this->invoice($this->acme, ['status' => 'issued']);

        $this->api()->deleteJson("/api/invoices/{$invoice->id}")
            ->assertStatus(422)
            ->assertJsonPath('error.message', 'Cannot delete issued invoice');

        $this->assertNotNull($this->reload($invoice));
    }

    public function test_delete_removes_draft_and_audits(): void
    {
        $invoice = $this->invoice($this->acme);

        $this->api()->deleteJson("/api/invoices/{$invoice->id}")
            ->assertOk()
            ->assertJsonPath('message', 'Invoice deleted');

        $this->assertNull($this->reload($invoice));
        $this->assertDatabaseHas('audit_logs', ['action' => 'Invoice.deleted', 'entity_id' => $invoice->id]);
    }

    public function test_failed_audit_keeps_draft_unchanged(): void
    {
        $invoice = $this->invoice($this->acme);

        $this->partialMock(AuditService::class, fn ($mock) => $mock
            ->shouldReceive('logUpdated')
            ->andThrow(new RuntimeException('audit unavailable')));

        $this->api()->putJson("/api/invoices/{$invoice->id}", $this->payload(['buyer_name' => 'Renamed Buyer']))
            ->assertServerError();

        $this->assertSame('Buyer', $this->reload($invoice)->buyer_name);
    }

    public function test_rival_invoice_cannot_be_changed(): void
    {
        $theirs = $this->invoice($this->rival);

        $this->api()->putJson("/api/invoices/{$theirs->id}", $this->payload(['buyer_name' => 'Renamed Buyer']))
            ->assertNotFound();

        $this->assertSame('Buyer', $this->reload($theirs)->buyer_name);
    }

    private function api(): self
    {
        return $this->withToken($this->token);
    }

    private function payload(array $overrides): array
    {
        return [
            'invoice_number' => 'INV-'.uniqid(),
            'type' => 'simplified',
            'issue_date' => now()->toDateString(),
            'buyer_name' => 'Buyer',
            'lines' => [[
                'description' => 'Item',
                'quantity' => 1,
                'unit_price' => 100,
            ]],
            ...$overrides,
        ];
    }

    private function invoice(Organization $organization, array $attributes = []): Invoice
    {
        $this->number++;

        $invoice = (new Invoice)->forceFill([
            'org_id' => $organization->id,
            'invoice_number' => 'INV-'.$this->number,
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

    private function reload(Invoice $invoice): ?Invoice
    {
        return Invoice::withoutTenantScope(fn () => Invoice::find($invoice->id));
    }
}
