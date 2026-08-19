<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * Keeps docs/openapi.yaml equal to the API it describes.
 *
 * Maintained by hand it drifted to describing sixteen paths out of a hundred
 * and twenty-nine, including a prefix that answers 301 and omitting the
 * licence-authenticated surface integrators actually use. Nothing caught it,
 * because nothing was comparing the two.
 *
 * A wrong description costs more than a missing one: it is followed. This
 * fails the build the moment a route is added, renamed or re-secured without
 * regenerating, so the file cannot quietly become fiction again.
 *
 *     php artisan masaar:openapi
 */
class OpenapiDriftTest extends TestCase
{
    private const SPEC = 'docs/openapi.yaml';

    public function test_committed_spec_matches_the_routes(): void
    {
        $committed = (string) file_get_contents(base_path(self::SPEC));

        Artisan::call('masaar:openapi', ['--print' => true]);
        $generated = Artisan::output();

        $this->assertSame(
            $this->normalise($committed),
            $this->normalise($generated),
            self::SPEC.' is out of date with the route table. Run: php artisan masaar:openapi'
        );
    }

    /**
     * Every API route appears, so a new endpoint cannot ship undescribed.
     */
    public function test_every_api_route_is_described(): void
    {
        $spec = Yaml::parseFile(base_path(self::SPEC));
        $described = array_keys($spec['paths'] ?? []);

        $missing = [];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/')) {
                continue;
            }

            $path = '/'.$route->uri();

            if (! in_array($path, $described, true)) {
                $missing[] = $path;
            }
        }

        $this->assertSame(array_values(array_unique($missing)), [],
            'Undescribed routes. Run: php artisan masaar:openapi');
    }

    /**
     * The reverse: nothing described that no longer exists. This is the half
     * that let the deprecated /api/compliance/zatca prefix sit in the file
     * long after the API stopped serving it.
     */
    public function test_no_described_route_is_gone(): void
    {
        $spec = Yaml::parseFile(base_path(self::SPEC));

        $live = [];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            $live[] = '/'.$route->uri();
        }

        $stale = array_values(array_diff(array_keys($spec['paths'] ?? []), $live));

        $this->assertSame([], $stale,
            'Described paths that no longer route anywhere. Run: php artisan masaar:openapi');
    }

    /**
     * A route behind a credential must say so, or an integrator reads it as
     * open and finds out at runtime.
     */
    public function test_guarded_routes_declare_security(): void
    {
        $spec = Yaml::parseFile(base_path(self::SPEC));
        $undeclared = [];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/')) {
                continue;
            }

            $guarded = array_intersect(
                ['jwt.auth', 'license', 'api.key', 'metrics'],
                array_map(
                    static fn ($m) => is_string($m) ? explode(':', $m)[0] : '',
                    $route->gatherMiddleware()
                )
            );

            if ($guarded === []) {
                continue;
            }

            foreach ($spec['paths']['/'.$route->uri()] ?? [] as $operation) {
                if (($operation['security'] ?? []) === []) {
                    $undeclared[] = '/'.$route->uri();
                }
            }
        }

        $this->assertSame(array_values(array_unique($undeclared)), [],
            'Guarded routes described as public.');
    }

    /**
     * Trailing whitespace and line endings differ between a file on disk and
     * command output; neither is drift.
     */
    private function normalise(string $yaml): string
    {
        return trim(str_replace("\r\n", "\n", $yaml));
    }

    /**
     * OpenAPI requires operationId to be unique across the document, and
     * generators key their client methods on it — duplicates silently collapse
     * two endpoints into one.
     *
     * Controller-and-method alone is not unique here: twenty-three controller
     * methods are routed on both the session and licence surfaces, and
     * apiResource serves update under PUT and PATCH.
     */
    public function test_operation_ids_are_unique(): void
    {
        $spec = Yaml::parseFile(base_path(self::SPEC));
        $seen = [];
        $duplicates = [];

        foreach ($spec['paths'] ?? [] as $path => $methods) {
            foreach ($methods as $method => $operation) {
                if (! is_array($operation) || ! isset($operation['operationId'])) {
                    continue;
                }

                $id = $operation['operationId'];

                if (isset($seen[$id])) {
                    $duplicates[] = "{$id}: {$seen[$id]} and ".strtoupper((string) $method)." {$path}";
                }

                $seen[$id] = strtoupper((string) $method)." {$path}";
            }
        }

        $this->assertSame([], $duplicates, implode("\n", $duplicates));
    }
}
