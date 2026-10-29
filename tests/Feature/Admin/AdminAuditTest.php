<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Domains\Audit\Models\AuditLog;
use App\Domains\Auth\Models\User;
use App\Domains\Platform\Http\Middleware\AuditAdminAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * Every change a platform admin makes is on the record.
 *
 * Licences kept a trail of their own. Kill switches, settings, the offline
 * queue and webhook replays wrote to the application log at most - a file
 * that rotates and that nobody can be asked to produce for an auditor - and
 * user accounts had no admin actions at all until now.
 */
class AdminAuditTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'audit-token';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['is_platform_admin' => true, 'email' => 'ops@example.test']);
    }

    /**
     * The guarantee, rather than one example of it: no admin route that
     * changes anything is outside the audit. Driven off the router, so a
     * route added tomorrow is held to it.
     */
    public function test_every_admin_change_is_audited(): void
    {
        $unaudited = [];

        foreach (Route::getRoutes() as $route) {
            $name = (string) $route->getName();

            if (! str_starts_with($name, 'admin.') || $route->methods() === ['GET', 'HEAD']) {
                continue;
            }

            $middleware = $route->gatherMiddleware();

            if (! in_array('admin.audit', $middleware, true) && ! in_array(AuditAdminAction::class, $middleware, true)) {
                $unaudited[] = $name;
            }
        }

        $this->assertSame([], $unaudited, 'These admin actions change something and are not audited.');
    }

    /**
     * The admin API changes the same things the console does, so it is on
     * the same record: every route behind platform.admin that is not a read.
     */
    public function test_the_admin_api_is_audited(): void
    {
        $unaudited = [];

        foreach (Route::getRoutes() as $route) {
            $middleware = $route->gatherMiddleware();

            if (! in_array('platform.admin', $middleware, true) || $route->methods() === ['GET', 'HEAD']) {
                continue;
            }

            if (! in_array('admin.audit', $middleware, true)) {
                $unaudited[] = implode('|', $route->methods()).' /'.$route->uri();
            }
        }

        $this->assertSame([], $unaudited, 'These admin routes change something and are not audited.');
    }

    public function test_an_api_change_names_its_admin(): void
    {
        $token = JWTAuth::fromUser($this->admin);

        $this->withToken($token)->postJson('/api/admin/dashboard/offline-queue/process')->assertSuccessful();

        $entry = AuditLog::where('action', 'admin.api/admin/dashboard/offline-queue/process')->sole();
        $this->assertSame((string) $this->admin->id, $entry->user_id);
        $this->assertSame('AdminApi', $entry->entity_type);
        $this->assertSame('succeeded', $entry->metadata['outcome']);
    }

    /**
     * An edit records what it changed from and to - only what changed.
     */
    public function test_a_settings_change_keeps_both_values(): void
    {
        $this->send('/admin/settings', ['alerts.backlog_warning' => '250'])->assertSessionHas('success');

        $entry = AuditLog::where('action', 'admin.settings.update')->first();

        $this->assertSame((string) $this->admin->id, $entry?->user_id);
        $this->assertSame('Setting', $entry->entity_type);
        $this->assertSame(['alerts.backlog_warning' => 250], $entry->new_values);
        $this->assertSame(['alerts.backlog_warning'], array_keys($entry->old_values));
    }

    public function test_secrets_are_never_written_down(): void
    {
        $clerk = User::factory()->create();

        $this->send('/admin/users/'.$clerk->id.'/suspend', [
            'reason' => 'Shared their password',
            'password' => 'hunter2-plain',
            'api_secret' => 'sk_live_abcdef',
        ]);

        $serialised = AuditLog::all()->toJson();

        $this->assertStringNotContainsString('hunter2-plain', $serialised);
        $this->assertStringNotContainsString('sk_live_abcdef', $serialised);
        $this->assertStringContainsString('Shared their password', $serialised);
    }

    /**
     * Masked by the name of the field: one-time codes, PINs, cards, bank
     * accounts, authorization headers and keys of every spelling, nested or
     * not.
     */
    public function test_secret_fields_are_masked_by_name(): void
    {
        $clerk = User::factory()->create();

        $this->send('/admin/users/'.$clerk->id.'/suspend', [
            'reason' => 'Left the company',
            'otp' => '481516',
            'pin' => '2342',
            'card_number' => '5500000000000004',
            'cvv' => '737',
            'iban' => 'AE070331234567890123456',
            'passphrase' => 'correct horse',
            'headers' => ['Authorization' => 'Basic dXNlcjpwYXNz', 'X-Api-Key' => 'k-7f3a9'],
            'shipping' => 'kept',
        ]);

        $input = AuditLog::where('action', 'admin.users.suspend')->sole()->metadata['input'];

        foreach (['otp', 'pin', 'card_number', 'cvv', 'iban', 'passphrase'] as $field) {
            $this->assertSame('[redacted]', $input[$field], $field);
        }
        $this->assertSame(['Authorization' => '[redacted]', 'X-Api-Key' => '[redacted]'], $input['headers']);
        $this->assertSame('kept', $input['shipping']);
        $this->assertSame('Left the company', $input['reason']);
    }

    /**
     * And by its shape, wherever it is written: a card number, an IBAN or a
     * bearer token pasted into a reason is masked, the rest of it kept.
     */
    public function test_secret_values_are_masked_by_shape(): void
    {
        $clerk = User::factory()->create();

        $this->send('/admin/users/'.$clerk->id.'/suspend', [
            'reason' => 'Card 4111 1111 1111 1111, IBAN SA03 8000 0000 6080 1016 7519, Bearer eyJhbGciOi.eyJzdWIiOi.c2lnbmF0dXJl',
        ]);

        $entry = AuditLog::where('action', 'admin.users.suspend')->sole();
        $reason = $entry->metadata['input']['reason'];

        $this->assertStringNotContainsString('4111 1111 1111 1111', $reason);
        $this->assertStringNotContainsString('6080 1016 7519', $reason);
        $this->assertStringNotContainsString('c2lnbmF0dXJl', $reason);
        $this->assertStringContainsString('Card ', $reason);

        // Masked before the entry is chained, so the chain still verifies.
        $this->artisan('platform:verify-audit-chain')->assertSuccessful();
    }

    /**
     * The record is of changes. Reading a screen is not one.
     */
    public function test_reading_a_screen_is_not_recorded(): void
    {
        $this->asRequest(fn () => $this->actingAs($this->admin)->get('/admin/users')->assertOk());

        $this->assertSame(0, AuditLog::where('action', 'like', 'admin.%')->count());
    }

    public function test_the_log_filters_by_category(): void
    {
        $clerk = User::factory()->create(['email' => 'clerk@acme.test']);

        $this->send('/admin/users/'.$clerk->id.'/suspend', ['reason' => 'Left the company']);

        AuditLog::create([
            'user_id' => $clerk->id,
            'action' => 'security.login.succeeded',
            'entity_type' => 'User',
            'entity_id' => $clerk->id,
        ]);

        $this->asRequest(fn () => $this->actingAs($this->admin)->get('/admin/audit?category=admin.'))
            ->assertOk()
            ->assertSee('admin.users.suspend')
            ->assertSee('Left the company')
            ->assertDontSee('security.login.succeeded');
    }

    /**
     * @param  array<string, string>  $data
     */
    private function send(string $uri, array $data): TestResponse
    {
        return $this->asRequest(fn () => $this->actingAs($this->admin)
            ->withSession(['_token' => self::TOKEN])
            ->post($uri, ['_token' => self::TOKEN, ...$data]));
    }
}
