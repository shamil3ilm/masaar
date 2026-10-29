<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Domains\Auth\Models\User;
use App\Support\Errors\ErrorCode;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Signing in to the API waits for a verified address, as the portal does.
 *
 * The API issued a token to any account with the right password, so an
 * address nobody had proved was the way in that the portal had closed.
 */
class ApiEmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
    }

    public function test_an_unverified_login_gets_no_token(): void
    {
        User::factory()->unverified()->create(['email' => 'new@example.test']);

        $this->postJson('/api/auth/login', ['email' => 'new@example.test', 'password' => 'password'])
            ->assertForbidden()
            ->assertJsonPath('error.code', ErrorCode::AUTH_EMAIL_NOT_VERIFIED->value)
            ->assertJsonMissingPath('data.token');
    }

    /**
     * Only after the right password, so it reveals nothing about an address.
     */
    public function test_a_wrong_password_reveals_nothing(): void
    {
        User::factory()->unverified()->create(['email' => 'new@example.test']);

        $this->postJson('/api/auth/login', ['email' => 'new@example.test', 'password' => 'wrong'])
            ->assertUnauthorized();
    }

    public function test_a_verified_login_gets_a_token(): void
    {
        User::factory()->create(['email' => 'ok@example.test']);

        $this->postJson('/api/auth/login', ['email' => 'ok@example.test', 'password' => 'password'])
            ->assertOk()
            ->assertJsonStructure(['data' => ['token' => ['access_token']]]);
    }

    public function test_the_link_can_be_sent_again(): void
    {
        $user = User::factory()->unverified()->create(['email' => 'new@example.test']);

        $this->postJson('/api/auth/email/resend', ['email' => 'new@example.test'])->assertOk();
        $this->postJson('/api/auth/email/resend', ['email' => 'nobody@example.test'])->assertOk();

        Notification::assertSentTo($user, VerifyEmail::class);
        Notification::assertCount(1);
    }

    /**
     * A deployment not filing with production says so on every page.
     */
    public function test_a_test_environment_is_announced(): void
    {
        config(['fatoora.environment' => 'simulation']);

        $this->get('/login')->assertDontSee('not legally filed');
        $this->getJson('/api/health')->assertJsonPath('zatca_environment', 'simulation');

        $admin = User::factory()->create(['is_platform_admin' => true]);

        $this->actingAs($admin)->get('/admin')->assertSee('ZATCA Simulation: invoices here are not legally filed');
    }
}
