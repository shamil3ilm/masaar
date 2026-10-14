<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * A fixed path is reached by the fixed path, not by the parameter above it.
 *
 * Laravel matches in declaration order, so a literal segment nested under a
 * dynamic sibling only works if it is declared first. Nothing warns when it
 * is not: the request matches the dynamic route, the literal arrives as the
 * parameter value, and the handler looks up a record whose id happens to be
 * the word someone meant as a path.
 *
 * GET /api/organizations/branches did exactly that. It was declared after
 * GET /api/organizations/{organization}, and {organization} carries no
 * pattern, so listing a taxpayer's branches resolved to "show the
 * organization whose id is the string branches" and answered 404 - a 404
 * that looks like an empty account rather than a route that cannot be
 * reached. POST to the same path worked, because no POST
 * /api/organizations/{organization} exists to catch it, which is the kind of
 * half-working that survives a smoke test.
 *
 * Two ways to be safe and the codebase uses both: declare the literal first,
 * as /api/webhooks/events does, or constrain the parameter so it cannot match
 * a word, as the portal's UUID-constrained ids do. This asserts the outcome
 * rather than either technique, so a route is free to use whichever fits.
 */
class RouteShadowTest extends TestCase
{
    /**
     * Routes whose URI cannot be turned into a request to test with.
     *
     * Laravel's own fallbacks and anything a package registers outside the
     * normal shape; they are not ours to order.
     */
    private const SKIP = ['_boost/'];

    public function test_every_fixed_path_is_reachable(): void
    {
        $shadowed = [];
        $checked = 0;

        foreach (Route::getRoutes() as $route) {
            // Only routes made entirely of literal segments can be shadowed
            // in this way; a route with its own parameter is the shadower.
            if ($route->parameterNames() !== []) {
                continue;
            }

            $uri = $route->uri();

            if ($this->skipped($uri)) {
                continue;
            }

            foreach ($route->methods() as $method) {
                if (in_array($method, ['HEAD', 'OPTIONS'], true)) {
                    continue;
                }

                $checked++;

                $hit = $this->match($method, $uri);

                if ($hit !== null && $hit !== $uri) {
                    $shadowed[] = $method.' /'.$uri.'  is answered by  '.$hit;
                }
            }
        }

        $this->assertGreaterThan(50, $checked, 'the route table was not read');

        $this->assertSame([], $shadowed,
            'These paths are answered by a route declared above them, with the literal segment '
            .'arriving as a parameter value. Declare the fixed path before the dynamic one, or '
            .'constrain the parameter so it cannot match the word.');
    }

    /**
     * The URI that actually answers, or null when nothing does.
     */
    private function match(string $method, string $uri): ?string
    {
        try {
            return Route::getRoutes()->match(Request::create('/'.$uri, $method))->uri();
        } catch (\Throwable) {
            // A method-not-allowed or not-found here is not shadowing; it is
            // a route this harness cannot build a request for.
            return null;
        }
    }

    private function skipped(string $uri): bool
    {
        foreach (self::SKIP as $prefix) {
            if (str_starts_with($uri, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
