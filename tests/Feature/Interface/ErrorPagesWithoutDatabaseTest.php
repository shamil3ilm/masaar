<?php

declare(strict_types=1);

namespace Tests\Feature\Interface;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;
use Throwable;

/**
 * A failure on our side is often the database. The pages for it must be
 * drawn without one, or the visitor gets the framework's bare page in their
 * place - so here the database cannot be reached at all, not merely the
 * theme refused.
 *
 * No RefreshDatabase: there is no database for it to refresh.
 */
class ErrorPagesWithoutDatabaseTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.connections.unreachable' => [
                'driver' => 'sqlite',
                'database' => storage_path('framework/testing/no-such-database.sqlite'),
                'foreign_key_constraints' => true,
            ],
            'database.default' => 'unreachable',
        ]);
        DB::purge();

        // Outside the web group: with the database gone, its middleware
        // (SetLocale reads the platform's language) fails first, and that
        // failure is the 500 below. Here the page is all that is drawn.
        foreach ([500, 502, 503, 504] as $status) {
            Route::get("/_status/{$status}", fn () => throw new HttpException($status));
        }

        Route::middleware('web')->get('/_status/query', fn () => DB::table('users')->count());
    }

    public function test_the_database_is_really_unreachable(): void
    {
        $this->assertFileDoesNotExist(storage_path('framework/testing/no-such-database.sqlite'));
        $this->expectException(Throwable::class);

        DB::connection()->getPdo();
    }

    public function test_server_failure_pages_need_no_database(): void
    {
        foreach ([500 => 'Something went wrong on our side', 502 => 'Something went wrong on our side', 503 => 'Service unavailable', 504 => 'Something went wrong on our side'] as $status => $title) {
            $this->get("/_status/{$status}")
                ->assertStatus($status)
                ->assertSee($title)
                ->assertSee('<span dir="ltr">'.$status.'</span>', false);
        }
    }

    /** The real thing: a query that cannot run, and the page it leads to. */
    public function test_a_failed_query_gets_our_page(): void
    {
        $this->get('/_status/query')
            ->assertStatus(500)
            ->assertSee('Something went wrong on our side')
            ->assertDontSee('no-such-database');
    }
}
