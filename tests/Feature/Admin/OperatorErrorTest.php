<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Domains\Auth\Models\User;
use App\Domains\Licensing\Exceptions\LicenseException;
use App\Domains\Licensing\Services\LicenseManagementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\TestCase;

/**
 * What the admin banner says when an action fails.
 *
 * It put the exception's own message on screen. A refusal written to be read
 * still is; anything else - SQL, a path, a library's internals - is a sentence
 * and a reference, and the detail is in the log under that reference.
 */
class OperatorErrorTest extends TestCase
{
    use RefreshDatabase;

    private const LICENCE = '0193ffff-0000-7000-8000-00000000abcd';

    public function test_an_unexpected_failure_is_referenced(): void
    {
        $this->mock(LicenseManagementService::class)
            ->shouldReceive('suspendLicense')
            ->andThrow(new RuntimeException('SQLSTATE[HY000]: licenses on /var/www/storage'));

        Log::spy();

        $this->suspend();

        $banner = (string) session('error');
        $this->assertStringNotContainsString('SQLSTATE', $banner);
        $this->assertMatchesRegularExpression('/Reference ([A-Z0-9]{8})/', $banner);

        preg_match('/Reference ([A-Z0-9]{8})/', $banner, $match);
        Log::shouldHaveReceived('error')->withArgs(
            fn (string $message, array $context): bool => ($context['reference'] ?? null) === $match[1]
                && $context['exception'] instanceof RuntimeException
        );
    }

    public function test_a_refusal_keeps_its_words(): void
    {
        $this->mock(LicenseManagementService::class)
            ->shouldReceive('suspendLicense')
            ->andThrow(new LicenseException('License is already suspended', 'LICENSE_SUSPENDED'));

        $this->suspend();

        $this->assertSame('License is already suspended', session('error'));
    }

    private function suspend(): void
    {
        $this->actingAs(User::factory()->create(['is_platform_admin' => true]))
            ->post('/admin/licences/'.self::LICENCE.'/suspend', ['reason' => 'Non-payment'])
            ->assertRedirect()
            ->assertSessionHas('error');
    }
}
