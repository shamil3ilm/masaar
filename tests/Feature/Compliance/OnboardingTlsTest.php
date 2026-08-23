<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Console\Commands\FatooraOnboarding;
use Illuminate\Http\Client\PendingRequest;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Tests\TestCase;

/**
 * The onboarding command talks to ZATCA over TLS, and verifies it.
 *
 * This is the path that carries the portal OTP to the authority and brings
 * back the production CSID and its secret. It opened every call with
 * Http::withoutVerifying(), unconditionally, so --target=production reached
 * the live endpoint with verification off.
 */
class OnboardingTlsTest extends TestCase
{
    public function test_verification_is_on_by_default(): void
    {
        $this->assertNotFalse(
            $this->verifyOption(),
            'The onboarding command disabled TLS verification.'
        );
    }

    public function test_config_can_turn_verification_off(): void
    {
        config(['fatoora.ssl_verify' => false]);

        $this->assertFalse(
            $this->verifyOption(),
            'fatoora.ssl_verify was ignored by the onboarding command.'
        );
    }

    /**
     * The endpoints come from config rather than a second copy inside the
     * command, so the console and the client cannot drift apart.
     */
    public function test_endpoints_come_from_config(): void
    {
        config(['fatoora.endpoints.production' => 'https://example.test/core']);

        $command = $this->command(['--target' => 'production', '--step' => 'info']);

        $this->assertSame(
            'https://example.test/core',
            (new \ReflectionProperty($command, 'baseUrl'))->getValue($command)
        );
    }

    private function verifyOption(): mixed
    {
        $client = (new \ReflectionMethod(FatooraOnboarding::class, 'zatca'))
            ->invoke(app(FatooraOnboarding::class));

        $this->assertInstanceOf(PendingRequest::class, $client);

        $options = (new \ReflectionProperty($client, 'options'))->getValue($client);

        return $options['verify'] ?? null;
    }

    /**
     * handle() reads --target through the command's own input, so the command
     * has to be run rather than constructed for baseUrl to be set. Artisan
     * would build its own instance, leaving this one untouched.
     */
    private function command(array $options): FatooraOnboarding
    {
        $command = app(FatooraOnboarding::class);
        $command->setLaravel($this->app);

        $command->run(
            new ArrayInput($options),
            new NullOutput
        );

        return $command;
    }
}
