<?php

declare(strict_types=1);

use App\Domains\Auth\Models\User;
use App\Domains\Organization\Models\ComplianceProfile;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function makeComplianceAuthToken(): array
{
    $org = Organization::create(['name' => 'Test Org', 'country' => 'SA', 'status' => 'active']);
    $user = User::factory()->create();
    $user->organizations()->attach($org->id, ['role' => 'admin', 'status' => 'active']);
    $token = auth('api')->login($user);

    return [$org, $user, $token];
}

it('lists compliance profiles for an organization', function () {
    [$org, $user, $token] = makeComplianceAuthToken();

    ComplianceProfile::create([
        'org_id' => $org->id,
        'jurisdiction' => 'SA',
        'engine' => 'fatoora',
        'status' => 'active',
        'settings' => [],
    ]);

    $response = $this->withToken($token)
        ->getJson("/api/organizations/{$org->id}/compliance-profiles");

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonCount(1, 'data');
});

it('creates a compliance profile', function () {
    [$org, $user, $token] = makeComplianceAuthToken();

    $response = $this->withToken($token)
        ->postJson("/api/organizations/{$org->id}/compliance-profiles", [
            'jurisdiction' => 'AE',
            'engine' => 'fta',
            'status' => 'pending_onboarding',
            'settings' => ['vat_number' => '100000000000003'],
        ]);

    $response->assertStatus(201)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.jurisdiction', 'AE');
});

it('deletes a compliance profile', function () {
    [$org, $user, $token] = makeComplianceAuthToken();

    $profile = ComplianceProfile::create([
        'org_id' => $org->id,
        'jurisdiction' => 'SA',
        'engine' => 'fatoora',
        'status' => 'active',
        'settings' => [],
    ]);

    $response = $this->withToken($token)
        ->deleteJson("/api/organizations/{$org->id}/compliance-profiles/{$profile->id}");

    $response->assertOk()->assertJsonPath('success', true);
    expect(ComplianceProfile::find($profile->id))->toBeNull();
});
