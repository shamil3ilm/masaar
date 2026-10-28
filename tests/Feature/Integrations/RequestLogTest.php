<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use App\Domains\Auth\Models\User;
use App\Domains\Licensing\Enums\LicenseEnvironment;
use App\Domains\Licensing\Enums\LicenseTier;
use App\Domains\Licensing\Models\ApiKey;
use App\Domains\Licensing\Models\ApiRequestLog;
use App\Domains\Licensing\Models\License;
use App\Domains\Organization\Models\Organization;
use App\Domains\Platform\Customization\Customizations;
use App\Domains\Platform\Customization\Level;
use App\Domains\Platform\Customization\Scope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Each partner API request is logged for its organization - method, path,
 * answer, key, time, request id and error code, and nothing a request or
 * answer carried - for its administrators to search, and kept for the days
 * the organization's customization says.
 */
class RequestLogTest extends TestCase
{
    use RefreshDatabase;

    private Organization $acme;

    private User $admin;

    /** @var array{key: string, secret: string} */
    private array $licence;

    protected function setUp(): void
    {
        parent::setUp();

        $this->acme = Organization::create(['name' => 'Acme Trading', 'country' => 'SA', 'vat_number' => '300000000000003', 'status' => 'active']);
        $this->admin = User::factory()->create();
        $this->admin->organizations()->attach($this->acme->id, ['role' => 'admin', 'status' => 'active']);
        $issued = License::createWithCredentials(['org_id' => $this->acme->id, 'organization_name' => 'Acme', 'contact_email' => 'ops@acme.test',
            'tier' => LicenseTier::Professional, 'scopes' => ['invoice.read', 'webhook.manage']]);
        $this->licence = ['key' => $issued['api_key'], 'secret' => $issued['api_secret']];
    }

    public function test_requests_are_logged_without_bodies(): void
    {
        [$key, $secret] = $this->key(['invoice.read']);

        $this->send($key->api_key, $secret, 'GET', '/api/v1/invoices?search=Huda', 'request-ok')->assertOk();
        $this->send($key->api_key, $secret, 'GET', '/api/v1/webhooks', 'request-scope')->assertForbidden();
        $this->send($key->api_key, 'wrong', 'GET', '/api/v1/invoices', 'request-secret')->assertUnauthorized();

        $logs = ApiRequestLog::acrossTenants()->orderBy('occurred_at')->orderBy('request_id')->get();

        $this->assertCount(2, $logs, 'a call nobody could be identified for is not anyone\'s to read');
        $ok = $logs->firstWhere('request_id', 'request-ok');
        $refused = $logs->firstWhere('request_id', 'request-scope');

        $this->assertSame(['GET', '/api/v1/invoices', 200, null, $key->id, $this->acme->id], [$ok->method, $ok->path, $ok->status, $ok->error_code, $ok->api_key_id, $ok->org_id]);
        $this->assertSame([403, 'AUTH_SCOPE_DENIED', '/api/v1/webhooks'], [$refused->status, $refused->error_code, $refused->path]);

        foreach ($logs as $log) {
            $this->assertStringNotContainsString($secret, json_encode($log->getAttributes()));
            $this->assertStringNotContainsString('Huda', json_encode($log->getAttributes()));
        }
    }

    public function test_administrators_search_the_log(): void
    {
        [$key, $secret] = $this->key(['invoice.read']);
        $this->send($key->api_key, $secret, 'GET', '/api/v1/invoices', 'request-one')->assertOk();
        $this->send($key->api_key, $secret, 'GET', '/api/v1/webhooks', 'request-two')->assertForbidden();
        $this->send($this->licence['key'], $this->licence['secret'], 'GET', '/api/v1/webhooks', 'request-three')->assertOk();

        $this->page()->assertOk()->assertSee('request-one')->assertSee('request-two')->assertSee('request-three')->assertSee('The licence key')->assertSee('ERP');
        $this->page(['outcome' => 'refused'])->assertSee('AUTH_SCOPE_DENIED')->assertDontSee('request-one')->assertDontSee('request-three');
        $this->page(['q' => 'request-three'])->assertSee('request-three')->assertDontSee('request-two');
        $this->page(['key' => 'licence'])->assertSee('request-three')->assertDontSee('request-one');
    }

    public function test_only_administrators_read_the_log(): void
    {
        $member = User::factory()->create();
        $member->organizations()->attach($this->acme->id, ['role' => 'member', 'status' => 'active']);

        $this->actingAs($member)->withSession(['portal_org_id' => $this->acme->id])
            ->get(route('portal.integrations.requests'))
            ->assertForbidden();
    }

    public function test_another_organizations_requests_stay_hidden(): void
    {
        $rival = Organization::create(['name' => 'Rival', 'country' => 'SA', 'status' => 'active']);
        $theirs = License::createWithCredentials(['org_id' => $rival->id, 'organization_name' => 'Rival', 'contact_email' => 'ops@rival.test', 'tier' => LicenseTier::Professional, 'scopes' => ['invoice.read']]);

        $this->send($theirs['api_key'], $theirs['api_secret'], 'GET', '/api/v1/invoices', 'rival-request')->assertOk();

        $this->page()->assertOk()->assertDontSee('rival-request');
    }

    public function test_each_organization_keeps_its_own_days(): void
    {
        $rival = Organization::create(['name' => 'Rival', 'country' => 'SA', 'status' => 'active']);
        $theirs = License::createWithCredentials(['org_id' => $rival->id, 'organization_name' => 'Rival', 'contact_email' => 'ops@rival.test', 'tier' => LicenseTier::Professional])['license'];
        app(Customizations::class)->set('integrations.request_log_days', Level::Organization, Scope::organization($this->acme->id), 7, $this->admin->id);

        $ours = License::withoutGlobalScopes()->where('org_id', $this->acme->id)->sole();
        $this->entry($this->acme, $ours, 'ours-old', 10);
        $this->entry($this->acme, $ours, 'ours-new', 2);
        $this->entry($rival, $theirs, 'theirs-old', 10);

        $this->page()->assertSee('ours-new')->assertDontSee('ours-old');

        Artisan::call('integrations:prune-request-log');

        $kept = ApiRequestLog::acrossTenants()->orderBy('request_id')->pluck('request_id')->all();
        $this->assertSame(['ours-new', 'theirs-old'], $kept);
    }

    private function entry(Organization $organization, License $licence, string $requestId, int $daysAgo): void
    {
        (new ApiRequestLog)->forceFill([
            'org_id' => $organization->id, 'license_id' => $licence->id, 'method' => 'GET', 'path' => '/api/v1/invoices',
            'status' => 200, 'request_id' => $requestId, 'duration_ms' => 5, 'occurred_at' => now()->subDays($daysAgo),
        ])->save();
    }

    /**
     * @param  list<string>  $scopes
     * @return array{ApiKey, string}
     */
    private function key(array $scopes): array
    {
        $secret = License::generateApiSecret();
        $key = new ApiKey(['name' => 'ERP', 'environment' => LicenseEnvironment::Sandbox, 'scopes' => $scopes]);
        $key->forceFill(['org_id' => $this->acme->id, 'api_key' => License::generateApiKey(LicenseEnvironment::Sandbox),
            'secret_hash' => License::hashSecret($secret), 'status' => ApiKey::ACTIVE])->save();

        return [$key, $secret];
    }

    private function send(string $key, string $secret, string $method, string $uri, string $requestId): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeaders(['X-API-Key' => $key, 'X-API-Secret' => $secret, 'X-Request-ID' => $requestId])->json($method, $uri);
    }

    /**
     * @param  array<string, string>  $query
     */
    private function page(array $query = []): TestResponse
    {
        return $this->actingAs($this->admin)->withSession(['portal_org_id' => $this->acme->id])->get(route('portal.integrations.requests', $query));
    }
}
