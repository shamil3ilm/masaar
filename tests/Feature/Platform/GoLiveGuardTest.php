<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Domains\Auth\Models\User;
use App\Domains\Compliance\Fatoora\Config\FatooraConfig;
use App\Domains\Organization\Models\Organization;
use App\Providers\AppServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * What stops a deployment going live misconfigured.
 *
 * Each case here was a setting with a default that works on a developer's
 * machine and is silently wrong in production: an authority name the code
 * did not know (which filed with the sandbox), and the settings only an
 * operator can supply.
 */
class GoLiveGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_unknown_authority_is_refused(): void
    {
        config(['fatoora.environment' => 'prod']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ZATCA_ENVIRONMENT');

        $this->guard('assertAuthorityIsKnown');
    }

    /**
     * And nothing falls back to the sandbox for one, even unbooted.
     */
    public function test_an_unknown_authority_has_no_endpoint(): void
    {
        config(['fatoora.environment' => 'Production']);

        $this->expectException(RuntimeException::class);

        FatooraConfig::getBaseUrl();
    }

    public function test_uae_is_not_checked_while_off(): void
    {
        config(['fta.enabled' => false, 'fta.environment' => 'nonsense']);

        $this->guard('assertAuthorityIsKnown');

        $this->addToAssertionCount(1);
    }

    public function test_production_needs_the_operator_settings(): void
    {
        $this->servingInProduction();
        config(['app.url' => 'http://localhost', 'mail.default' => 'log']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/APP_URL.*MAIL_MAILER/');

        $this->guard('assertProductionSettingsAreFilledIn');
    }

    public function test_a_template_placeholder_is_unset(): void
    {
        $this->servingInProduction();
        $this->filledIn();
        config(['security.api_key_pepper' => '<GENERATE_WITH_openssl_rand_-base64_32>']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('API_KEY_PEPPER');

        $this->guard('assertProductionSettingsAreFilledIn');
    }

    public function test_filled_in_settings_pass(): void
    {
        $this->servingInProduction();
        $this->filledIn();

        $this->guard('assertProductionSettingsAreFilledIn');

        $this->addToAssertionCount(1);
    }

    /**
     * A one-off command is how some of these get filled in, so it runs.
     */
    public function test_a_setup_command_still_runs(): void
    {
        $this->app['env'] = 'production';
        config(['app.url' => 'http://localhost', 'jwt.secret' => '']);

        $this->guard('assertProductionSettingsAreFilledIn');

        $this->addToAssertionCount(1);
    }

    public function test_uae_routes_are_gone_while_off(): void
    {
        config(['fta.enabled' => false]);

        $organization = Organization::create(['name' => 'Acme', 'country' => 'SA']);
        $user = User::factory()->create();
        $user->organizations()->attach($organization->id, ['role' => 'admin', 'status' => 'active']);
        $token = JWTAuth::claims(['org_id' => $organization->id, 'role' => 'admin'])->fromUser($user);

        $this->withToken($token)->getJson('/api/compliance/ae/submissions')->assertNotFound();

        config(['fta.enabled' => true]);

        $this->withToken($token)->getJson('/api/compliance/ae/submissions')->assertOk();
    }

    private function guard(string $name): void
    {
        $method = new ReflectionMethod(AppServiceProvider::class, $name);
        $method->invoke(new AppServiceProvider($this->app));
    }

    private function servingInProduction(): void
    {
        $this->app['env'] = 'production';
        (new ReflectionProperty($this->app, 'isRunningInConsole'))->setValue($this->app, false);
    }

    private function filledIn(): void
    {
        config([
            'app.url' => 'https://einvoice.client.sa',
            'jwt.secret' => str_repeat('s', 40),
            'security.api_key_pepper' => str_repeat('p', 40),
            'mail.default' => 'smtp',
            'mail.from.address' => 'noreply@client.sa',
        ]);
    }
}
