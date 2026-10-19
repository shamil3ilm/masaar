<?php

declare(strict_types=1);

namespace Tests\Feature\Interface;

use App\Domains\Auth\Models\User;
use App\Domains\Branding\Services\ThemeService;
use App\Http\Middleware\SetLocale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * The pages a visitor meets when something is wrong are ours, in either
 * language, and the product looks like itself outside its pages too: the
 * tab, the home screen and a shared link.
 */
class ErrorAndIconTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([403, 419, 429, 500, 503] as $status) {
            Route::middleware('web')->get("/_status/{$status}", fn () => throw new HttpException($status));
        }
    }

    public function test_each_error_has_our_page(): void
    {
        foreach ([403 => 'You cannot open this page', 419 => 'This page has expired', 429 => 'Too many requests', 500 => 'Something went wrong on our side', 503 => 'Service unavailable'] as $status => $title) {
            $this->get("/_status/{$status}")
                ->assertStatus($status)
                ->assertSee($title)
                ->assertSee('data-error-line', false)
                ->assertSee(route('privacy'), false);
        }
    }

    public function test_error_pages_speak_arabic(): void
    {
        $this->withSession([SetLocale::SESSION_KEY => 'ar'])->get('/_status/403')
            ->assertStatus(403)
            ->assertSee('dir="rtl"', false)
            ->assertSee(__('You cannot open this page', [], 'ar'));
    }

    public function test_the_failure_page_survives_without_data(): void
    {
        // As if the database were down: the theme cannot be read.
        $this->app->bind(ThemeService::class, fn () => throw new RuntimeException('The database is down.'));

        $this->get('/_status/500')->assertStatus(500)->assertSee('Something went wrong on our side');
    }

    public function test_the_manifest_names_the_application(): void
    {
        $this->get(route('app.manifest'))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/manifest+json')
            ->assertJsonPath('name', config('app.name'))
            ->assertJsonPath('icons.0.src', asset('icons/icon-192.png'));
    }

    public function test_pages_carry_the_app_icons(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('rel="apple-touch-icon"', false)
            ->assertSee(route('app.manifest'), false)
            ->assertSee('name="theme-color"', false)
            ->assertSee('property="og:image" content="'.asset('icons/og-image.png').'"', false);
    }

    public function test_signed_in_pages_carry_them_too(): void
    {
        $this->actingAs(User::factory()->create())->get(route('account.show'))->assertSee('rel="apple-touch-icon"', false);
    }
}
