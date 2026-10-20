<?php

declare(strict_types=1);

namespace Tests\Feature\Invoice;

use App\Domains\Auth\Models\User;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * A citizen's private healthcare (VATEX-SA-HEA) and zero-rated medicines
 * (VATEX-SA-35) are both category Z, which takes one exemption reason per
 * invoice (ZATCA XML Implementation Standard §8.4) - so a clinic's visit
 * with its medicines is two invoices, and the refusal says so, in both
 * languages.
 */
class CitizenCareMedicinesTest extends TestCase
{
    use RefreshDatabase;

    public function test_medicines_go_on_another_invoice(): void
    {
        $clinic = Organization::create(['name' => 'Al Shifa Clinic', 'country' => 'SA', 'vat_number' => '399999999900003']);
        $user = User::factory()->create();
        $user->organizations()->attach($clinic->id, ['role' => 'admin', 'status' => 'active']);
        $token = JWTAuth::claims(['org_id' => $clinic->id, 'role' => 'admin'])->fromUser($user);

        $message = $this->withToken($token)->postJson('/api/invoices', [
            'type' => 'simplified',
            'issue_date' => now()->toDateString(),
            'buyer_name' => 'Fahad Al Harbi', 'buyer_name_ar' => 'فهد الحربي', 'buyer_id' => '1047392816', 'buyer_id_scheme' => 'NAT',
            'lines' => [
                ['description' => 'Consultation', 'description_ar' => 'استشارة', 'quantity' => 1, 'unit_price' => '300.00', 'exempt_code' => 'VATEX-SA-HEA'],
                ['description' => 'Insulin pen', 'description_ar' => 'قلم إنسولين', 'quantity' => 1, 'unit_price' => '100.00', 'exempt_code' => 'VATEX-SA-35'],
            ],
        ])->assertUnprocessable()->json('error.details')['lines.1.exempt_code'][0];

        $this->assertStringContainsString('issue the medicines on a separate invoice', $message);
        $this->assertStringContainsString('فاتورة منفصلة', $message);
    }
}
