<?php

declare(strict_types=1);

namespace Tests\Feature\Invoice;

use App\Domains\Auth\Models\User;
use App\Domains\Compliance\Countries\SaudiArabia\Issuing\InvoiceIssuer as SaudiArabiaIssuer;
use App\Domains\Compliance\Engine\DTOs\IssueOutcome;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;
use App\Support\Errors\ErrorCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\MakesInvoices;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class UnifiedInvoiceIssuingTest extends TestCase
{
    use MakesInvoices;
    use RefreshDatabase;

    public function test_unified_issue_endpoint_issues_draft_invoice(): void
    {
        $org = Organization::create([
            'name' => 'Al Amal Tech LLC',
            'country' => 'SA',
            'vat_number' => '300000000000003',
        ]);

        $user = User::factory()->create();
        $org->users()->attach($user, ['role' => 'owner', 'status' => 'active']);

        $token = JWTAuth::claims(['org_id' => $org->id, 'role' => 'owner'])->fromUser($user);

        $invoice = $this->makeInvoice($org, 'INV-2026-001', [
            'status' => 'draft',
            'buyer_name' => 'شركة المشتري',
        ]);

        $this->mock(SaudiArabiaIssuer::class)
            ->shouldReceive('issue')
            ->once()
            ->andReturnUsing(function (Invoice $inv) {
                $inv->forceFill(['status' => 'issued', 'hash' => 'sample-hash-123'])->save();

                return IssueOutcome::issuedOnly();
            });

        $response = $this->withToken($token)
            ->postJson("/api/invoices/{$invoice->id}/issue");

        $response->assertOk()
            ->assertJsonPath('data.invoice_id', $invoice->id)
            ->assertJsonPath('data.status', 'issued');

        $this->assertDatabaseHas('invoices', [
            'id' => $invoice->id,
            'status' => 'issued',
        ]);
    }

    public function test_unified_issue_endpoint_rejects_already_issued_invoice(): void
    {
        $org = Organization::create([
            'name' => 'Al Amal Tech LLC',
            'country' => 'SA',
            'vat_number' => '300000000000003',
        ]);

        $user = User::factory()->create();
        $org->users()->attach($user, ['role' => 'owner', 'status' => 'active']);

        $token = JWTAuth::claims(['org_id' => $org->id, 'role' => 'owner'])->fromUser($user);

        $invoice = $this->makeInvoice($org, 'INV-2026-002', [
            'status' => 'issued',
            'icv' => 1,
            'hash' => 'hash-abc',
        ]);

        $response = $this->withToken($token)
            ->postJson("/api/invoices/{$invoice->id}/issue");

        $response->assertStatus(409)
            ->assertJsonPath('error.code', ErrorCode::VAL_INVOICE_ALREADY_ISSUED->value);
    }
}
