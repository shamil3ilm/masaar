<?php

declare(strict_types=1);

namespace Tests\Feature\Invoice;

use App\Domains\Auth\Models\User;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\MakesInvoices;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class UnifiedComplianceStatusTest extends TestCase
{
    use MakesInvoices;
    use RefreshDatabase;

    public function test_unified_compliance_status_endpoint_resolves_for_invoice(): void
    {
        $org = Organization::create([
            'name' => 'Saudi Tech Establishment',
            'country' => 'SA',
            'vat_number' => '300000000000003',
        ]);

        $user = User::factory()->create();
        $org->users()->attach($user, ['role' => 'owner', 'status' => 'active']);

        $token = JWTAuth::claims(['org_id' => $org->id, 'role' => 'owner'])->fromUser($user);

        $invoice = $this->makeInvoice($org, 'INV-2026-999', [
            'status' => 'issued',
            'icv' => 1,
            'hash' => 'dummy-hash-123',
        ]);

        $response = $this->withToken($token)
            ->getJson("/api/invoices/{$invoice->id}/compliance-status");

        $response->assertOk()
            ->assertJsonPath('data.invoice_id', $invoice->id)
            ->assertJsonPath('data.country', 'SA')
            ->assertJsonPath('data.compliance.icv', 1)
            ->assertJsonPath('data.compliance.hash', 'dummy-hash-123');
    }
}
