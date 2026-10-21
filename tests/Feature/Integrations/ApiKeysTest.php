<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use App\Domains\Auth\Models\User;
use App\Domains\Licensing\Enums\ApiScope;
use App\Domains\Licensing\Enums\LicenseTier;
use App\Domains\Licensing\Models\ApiKey;
use App\Domains\Licensing\Models\License;
use App\Domains\Organization\Models\Organization;
use App\Domains\Platform\Approvals\ApprovalPolicies;
use App\Domains\Platform\Approvals\Approvals;
use App\Domains\Platform\Models\ApprovalRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * An organization's administrators make and revoke their own API keys in
 * the portal. A key acts under the organization's licence, with the scopes
 * chosen from the licence's, an optional expiry and address list; its secret
 * is shown once and kept as a peppered hash; a production key waits for a
 * platform admin when the approval policy says so.
 */
class ApiKeysTest extends TestCase
{
    use RefreshDatabase;

    private Organization $acme;

    private User $admin;

    private User $member;

    private License $licence;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->acme = Organization::create(['name' => 'Acme Trading', 'country' => 'SA', 'vat_number' => '300000000000003', 'status' => 'active']);
        $this->admin = $this->memberAs('admin');
        $this->member = $this->memberAs('member');
        $this->licence = License::createWithCredentials([
            'org_id' => $this->acme->id, 'organization_name' => 'Acme Trading', 'contact_email' => 'ops@acme.test',
            'tier' => LicenseTier::Professional, 'expires_at' => now()->addYear(),
            'scopes' => ['invoice.submit', 'invoice.read', 'compliance.status', 'webhook.manage'],
        ])['license'];
    }

    public function test_the_secret_is_shown_once(): void
    {
        $response = $this->make(['name' => 'ERP', 'scopes' => ['invoice.read']]);
        $response->assertRedirect(route('portal.integrations.index'))->assertSessionHas('success');

        $made = session('api_key_made');
        $key = ApiKey::withoutTenantScope(fn () => ApiKey::query()->sole());

        $this->assertSame(License::hashSecret($made['secret']), $key->secret_hash);
        $this->assertNotSame($made['secret'], $key->secret_hash);
        $this->assertStringStartsWith('cp_test_', $key->api_key);
        $this->assertSame([$this->acme->id, 'active', ['invoice.read']], [$key->org_id, $key->status, $key->scopes]);

        $this->page()->assertOk()->assertSee($made['secret']);
        $this->page()->assertOk()->assertDontSee($made['secret'])->assertSee($key->api_key);
    }

    public function test_a_key_calls_with_its_scopes(): void
    {
        [$key, $secret] = $this->key(['scopes' => ['invoice.read']]);

        $this->api($key, $secret, 'GET', '/api/v1/invoices')->assertOk();
        $this->api($key, $secret, 'GET', '/api/v1/webhooks')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'AUTH_SCOPE_DENIED');
    }

    public function test_scopes_beyond_the_plan_are_refused(): void
    {
        $this->make(['name' => 'ERP', 'scopes' => ['reports.export']])->assertSessionHasErrors('scopes');

        $this->assertSame(0, ApiKey::withoutTenantScope(fn () => ApiKey::query()->count()));
    }

    public function test_only_administrators_manage_keys(): void
    {
        $this->actingAs($this->member)->withSession(['portal_org_id' => $this->acme->id])
            ->get(route('portal.integrations.index'))
            ->assertForbidden();

        $this->actingAs($this->member)->withSession(['portal_org_id' => $this->acme->id])
            ->post(route('portal.integrations.keys.store'), ['name' => 'ERP', 'environment' => 'sandbox', 'scopes' => ['invoice.read']])
            ->assertForbidden();
    }

    public function test_a_revoked_key_stops_at_once(): void
    {
        [$key, $secret] = $this->key(['scopes' => ['invoice.read']]);
        $this->api($key, $secret, 'GET', '/api/v1/invoices')->assertOk();

        $this->actingAs($this->admin)->withSession(['portal_org_id' => $this->acme->id])
            ->post(route('portal.integrations.keys.revoke', $key->id))
            ->assertSessionHas('success');

        $this->api($key, $secret, 'GET', '/api/v1/invoices')->assertUnauthorized();
        $this->assertSame($this->admin->id, ApiKey::withoutTenantScope(fn () => ApiKey::withTrashed()->find($key->id))->deleted_by);
    }

    public function test_expiry_and_addresses_are_enforced(): void
    {
        [$key, $secret] = $this->key(['scopes' => ['invoice.read'], 'allowed_ips' => "203.0.113.0/24\n198.51.100.7"]);

        $this->api($key, $secret, 'GET', '/api/v1/invoices', '203.0.113.9')->assertOk();
        $this->api($key, $secret, 'GET', '/api/v1/invoices', '192.0.2.1')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'API_KEY_ADDRESS_NOT_ALLOWED');

        ApiKey::withoutTenantScope(fn () => ApiKey::query()->whereKey($key->id)->update(['expires_at' => now()->subMinute()]));

        $this->api($key, $secret, 'GET', '/api/v1/invoices', '203.0.113.9')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'API_KEY_EXPIRED');
    }

    public function test_a_malformed_address_is_refused(): void
    {
        $this->make(['name' => 'ERP', 'scopes' => ['invoice.read'], 'allowed_ips' => '10.0.0.0/40'])->assertSessionHasErrors('allowed_ips');
    }

    public function test_usage_is_counted_per_key(): void
    {
        [$key, $secret] = $this->key(['scopes' => ['invoice.read']]);

        $this->api($key, $secret, 'GET', '/api/v1/invoices')->assertOk();
        $this->api($key, $secret, 'GET', '/api/v1/webhooks')->assertForbidden();

        $this->page()->assertSee('Today: 2 requests, 1 errors');
        $this->assertNotNull($key->fresh()->last_used_at);
    }

    public function test_a_production_key_waits_for_approval(): void
    {
        $owner = User::factory()->create(['is_platform_admin' => true, 'platform_role' => 'owner']);
        app(ApprovalPolicies::class)->save('api_keys.production', ['enabled' => true, 'approvals_required' => 1, 'approver_roles' => ['owner'], 'expires_after_hours' => 24], $owner->id);

        $this->make(['name' => 'ERP live', 'environment' => 'production', 'scopes' => ['invoice.read']])->assertSessionHasErrors('approval_reason');

        $this->make(['name' => 'ERP live', 'environment' => 'production', 'scopes' => ['invoice.read'], 'approval_reason' => 'Going live with our ERP'])
            ->assertSessionHas('warning');
        $secret = session('api_key_made')['secret'];
        $key = ApiKey::withoutTenantScope(fn () => ApiKey::query()->sole());
        $request = ApprovalRequest::query()->sole();

        $this->assertSame([ApiKey::PENDING, $request->id, 'api_keys.production'], [$key->status, $key->approval_request_id, $request->action]);
        $this->api($key, $secret, 'GET', '/api/v1/invoices')->assertForbidden()->assertJsonPath('error.code', 'API_KEY_PENDING_APPROVAL');
        $this->page()->assertSee('Waiting for approval');

        app(Approvals::class)->approve($request->id, $owner);

        $this->assertSame([ApiKey::ACTIVE, ApprovalRequest::EXECUTED], [$key->fresh()->status, $request->fresh()->status]);
        $this->api($key, $secret, 'GET', '/api/v1/invoices')->assertOk();
    }

    public function test_production_keys_skip_approval_by_default(): void
    {
        $this->make(['name' => 'ERP live', 'environment' => 'production', 'scopes' => ['invoice.read']])->assertSessionHas('success');

        $key = ApiKey::withoutTenantScope(fn () => ApiKey::query()->sole());
        $this->assertSame([ApiKey::ACTIVE, 'production'], [$key->status, $key->environment->value]);
        $this->assertStringStartsWith('cp_live_', $key->api_key);
    }

    public function test_the_page_renders_every_scope(): void
    {
        $all = array_map(fn (ApiScope $scope) => $scope->value, ApiScope::cases());
        $this->licence->update(['scopes' => $all]);
        $this->key(['scopes' => $all]);

        $page = $this->page()->assertOk();
        foreach (ApiScope::cases() as $scope) {
            $page->assertSee($scope->label());
        }
    }

    public function test_no_licence_means_no_keys(): void
    {
        $this->licence->revoke('Plan ended');

        $this->page()->assertOk()->assertSee('You cannot make an API key now');
        $this->make(['name' => 'ERP', 'scopes' => ['invoice.read']])->assertSessionHas('error');
        $this->assertSame(0, ApiKey::withoutTenantScope(fn () => ApiKey::query()->count()));
    }

    public function test_making_keys_is_rate_limited(): void
    {
        config(['licensing.api_keys.created_per_hour' => 2]);

        $this->make(['name' => 'One', 'scopes' => ['invoice.read']])->assertSessionHas('success');
        $this->make(['name' => 'Two', 'scopes' => ['invoice.read']])->assertSessionHas('success');
        $this->make(['name' => 'Three', 'scopes' => ['invoice.read']])->assertSessionHas('error');

        $this->assertSame(2, ApiKey::withoutTenantScope(fn () => ApiKey::query()->count()));
    }

    public function test_the_secret_is_never_logged(): void
    {
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $message) use (&$logged): void {
            $logged[] = $message->message.' '.json_encode($message->context);
        });

        [$key, $secret] = $this->key(['scopes' => ['invoice.read']]);
        $this->api($key, $secret.'x', 'GET', '/api/v1/invoices')->assertUnauthorized();
        $this->api($key, $secret, 'GET', '/api/v1/invoices')->assertOk();

        foreach ($logged as $line) {
            $this->assertStringNotContainsString($secret, $line);
        }
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    private function make(array $fields): TestResponse
    {
        return $this->actingAs($this->admin)->withSession(['portal_org_id' => $this->acme->id])
            ->post(route('portal.integrations.keys.store'), [...['environment' => 'sandbox'], ...$fields]);
    }

    /**
     * A key made through the portal, and its secret.
     *
     * @param  array<string, mixed>  $fields
     * @return array{ApiKey, string}
     */
    private function key(array $fields): array
    {
        $this->make(['name' => 'ERP', ...$fields])->assertSessionHas('success');

        $made = session('api_key_made');

        return [ApiKey::withoutTenantScope(fn () => ApiKey::query()->where('api_key', $made['key'])->sole()), $made['secret']];
    }

    private function api(ApiKey $key, string $secret, string $method, string $uri, string $address = '127.0.0.1'): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->withServerVariables(['REMOTE_ADDR' => $address])
            ->withHeaders(['X-API-Key' => $key->api_key, 'X-API-Secret' => $secret])
            ->json($method, $uri);
    }

    private function page(): TestResponse
    {
        return $this->actingAs($this->admin)->withSession(['portal_org_id' => $this->acme->id])->get(route('portal.integrations.index'));
    }

    private function memberAs(string $role): User
    {
        $user = User::factory()->create();
        $user->organizations()->attach($this->acme->id, ['role' => $role, 'status' => 'active']);

        return $user;
    }
}
