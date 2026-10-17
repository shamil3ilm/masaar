<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Providers\AppServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * Which host the application's links point at.
 *
 * Outside a developer's machine every link is built from APP_URL, so a forged
 * Host header cannot steer a password-reset link somewhere else. Locally they
 * follow the address being browsed, so php artisan serve works on whatever
 * host and port it was started on.
 */
class RootUrlTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        URL::forceRootUrl(null);
        URL::forceScheme(null);

        parent::tearDown();
    }

    public function test_local_links_follow_the_address(): void
    {
        $this->configureFor('local', 'http://localhost');

        $this->get('http://127.0.0.1:8000/')
            ->assertOk()
            ->assertSee('http://127.0.0.1:8000/login', false)
            ->assertDontSee('http://localhost/login', false);
    }

    public function test_elsewhere_links_ignore_the_host(): void
    {
        $this->configureFor('production', 'https://masaar.example');

        $this->get('http://forged.example/')
            ->assertOk()
            ->assertSee('https://masaar.example/login', false)
            ->assertDontSee('forged.example', false);
    }

    private function configureFor(string $environment, string $appUrl): void
    {
        URL::forceRootUrl(null);
        URL::forceScheme(null);
        config(['app.url' => $appUrl]);
        $this->app['env'] = $environment;

        $this->app->getProvider(AppServiceProvider::class)->configureRootUrl();
    }
}
