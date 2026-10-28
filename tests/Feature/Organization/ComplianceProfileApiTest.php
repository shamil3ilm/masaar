<?php

declare(strict_types=1);

use App\Domains\Auth\Models\User;
use App\Domains\Organization\Models\ComplianceProfile;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tymon\JWTAuth\Facades\JWTAuth;

uses(RefreshDatabase::class);

/**
 * A token as login issues it: naming the organization the caller acts in.
 * These minted tokens without one, so no tenant was ever set and nothing
 * here could see that the routes ignored whose organization they wrote to.
 */
function makeComplianceAuthToken(string $role = 'admin'): array
{
    $org = Organization::create(['name' => 'Test Org', 'country' => 'SA', 'status' => 'active']);
    $user = User::factory()->create();
    $user->organizations()->attach($org->id, ['role' => $role, 'status' => 'active']);
    $token = JWTAuth::claims(['org_id' => $org->id, 'role' => $role])->fromUser($user);

    return [$org, $user, $token];
}

/*
 * Another organization's profiles are out of reach.
 *
 * Nothing compared {organization} with the caller's: any token - one from
 * /api/auth/register would do - created a profile in any organization. A
 * profile's vat_number overrides the organization's on every invoice it signs
 * (BT-31, QR tag 2), and a suspended one stops all its filing.
 */
it('refuses another organization', function () {
    [, , $token] = makeComplianceAuthToken();
    $victim = Organization::create(['name' => 'Victim', 'country' => 'SA', 'status' => 'active']);
    $theirs = ComplianceProfile::create([
        'org_id' => $victim->id, 'jurisdiction' => 'AE', 'engine' => 'fta', 'status' => 'active', 'settings' => [],
    ]);

    $this->withToken($token)->postJson("/api/organizations/{$victim->id}/compliance-profiles", [
        'jurisdiction' => 'SA', 'engine' => 'fatoora', 'status' => 'suspended',
        'settings' => ['vat_number' => '399999999900003'],
    ])->assertNotFound();
    $this->withToken($token)->getJson("/api/organizations/{$victim->id}/compliance-profiles")->assertNotFound();
    $this->withToken($token)->deleteJson("/api/organizations/{$victim->id}/compliance-profiles/{$theirs->id}")->assertNotFound();

    expect(ComplianceProfile::acrossTenants()->where('org_id', $victim->id)->count())->toBe(1);
});

/*
 * A profile sets the seller VAT number and can stop filing; changing the VAT
 * number through the organization is already an administrator's call.
 */
it('refuses a member', function () {
    [$org, , $token] = makeComplianceAuthToken('member');

    $this->withToken($token)->postJson("/api/organizations/{$org->id}/compliance-profiles", [
        'jurisdiction' => 'SA', 'engine' => 'fatoora',
    ])->assertForbidden();
});

it('validates the settings it accepts', function () {
    [$org, , $token] = makeComplianceAuthToken();

    $this->withToken($token)->postJson("/api/organizations/{$org->id}/compliance-profiles", [
        'jurisdiction' => 'SA', 'engine' => 'fatoora', 'settings' => ['vat_number' => 'not-a-vat'],
    ])->assertStatus(422);

    $this->withToken($token)->postJson("/api/organizations/{$org->id}/compliance-profiles", [
        'jurisdiction' => 'SA', 'engine' => 'fatoora', 'settings' => ['anything' => str_repeat('x', 10)],
    ])->assertStatus(422);
});

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
