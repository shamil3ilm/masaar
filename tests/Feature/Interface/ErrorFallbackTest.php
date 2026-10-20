<?php

declare(strict_types=1);

namespace Tests\Feature\Interface;

use App\Http\Middleware\SetLocale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Number;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Every status a visitor can meet has our page, not only the six drawn for
 * it: a code without a view of its own falls to errors/4xx or errors/5xx,
 * which show the code and a line about what to do. 401 and 413 have their
 * own words, because there is something specific to do about each.
 *
 * Debug is off here (phpunit.xml pins it), as in production.
 */
class ErrorFallbackTest extends TestCase
{
    use RefreshDatabase;

    /** Statuses with no view of their own, and those 401 and 413 now have. */
    private const STATUSES = [400, 401, 402, 405, 408, 413, 422, 451, 501, 502, 504];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (self::STATUSES as $status) {
            Route::middleware('web')->get("/_status/{$status}", fn () => throw new HttpException($status, 'Internal detail of '.$status));
        }

        Route::middleware('web')->get('/_status/crash', fn () => throw new RuntimeException('Internal detail of a crash'));
    }

    public function test_every_status_has_our_page(): void
    {
        foreach (self::STATUSES as $status) {
            $this->get("/_status/{$status}")
                ->assertStatus($status)
                ->assertSee('data-error-line', false)
                ->assertSee('<span dir="ltr">'.$status.'</span>', false)
                ->assertSee(route('privacy'), false)
                ->assertDontSee('Internal detail');
        }
    }

    /** A method the address does not take: the router's 405, as it happens. */
    public function test_a_wrong_method_has_our_page(): void
    {
        $this->post(route('privacy'))
            ->assertStatus(405)
            ->assertSee('<span dir="ltr">405</span>', false)
            ->assertSee(__('This request could not be completed'));
    }

    public function test_unauthorized_offers_to_sign_in(): void
    {
        $this->get('/_status/401')
            ->assertStatus(401)
            ->assertSee(__('Sign in to continue'))
            ->assertSee('href="'.route('login').'"', false);
    }

    public function test_too_large_names_the_limit(): void
    {
        $this->get('/_status/413')
            ->assertStatus(413)
            ->assertSee(__('That file is too large'))
            ->assertSee(Number::fileSize(UploadedFile::getMaxFilesize()));
    }

    public function test_server_failures_offer_a_retry(): void
    {
        $this->get('/_status/502')
            ->assertStatus(502)
            ->assertSee(__('Something went wrong on our side'))
            ->assertSee('href="'.url('/_status/502').'"', false);
    }

    public function test_the_fallbacks_speak_arabic(): void
    {
        foreach ([405 => 'This request could not be completed', 413 => 'That file is too large', 504 => 'Something went wrong on our side'] as $status => $title) {
            $this->withSession([SetLocale::SESSION_KEY => 'ar'])->get("/_status/{$status}")
                ->assertStatus($status)
                ->assertSee('dir="rtl"', false)
                ->assertSee(__($title, [], 'ar'));
        }
    }

    public function test_exception_details_never_reach_visitors(): void
    {
        $this->get('/_status/crash')
            ->assertStatus(500)
            ->assertSee(__('Something went wrong on our side'))
            ->assertDontSee('Internal detail')
            ->assertDontSee('RuntimeException');
    }
}
