<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Every path an SDK calls is a path the API serves.
 *
 * All twelve SDKs called /v1/invoices and /api/compliance/zatca/..., neither
 * of which exists - the partner API is under /api/v1 and Saudi compliance is
 * /compliance/sa on the tenant API - so no SDK could create or file an
 * invoice. Nothing compared them with the route table.
 *
 * Checked: every full /api/... path literal in SDK source. The helpers that
 * turn a logical path into a full one (/api/v1 for a key and secret, /api for
 * a JWT) hold bare prefixes, which are skipped.
 */
class SdkPathTest extends TestCase
{
    private const SDKS = __DIR__.'/../../../sdks';

    /** Prefixes the routing helpers prepend; not request paths themselves. */
    private const PREFIXES = ['/api/', '/api/v1', '/api/compliance/sa/'];

    public function test_sdks_call_real_paths(): void
    {
        $routes = [];

        foreach (Route::getRoutes() as $route) {
            $routes[] = '#^/'.preg_replace('/\\\{[^}]+\\\}/', '[^/]+', preg_quote($route->uri(), '#')).'$#';
        }

        $unknown = [];

        foreach ($this->sources() as $path => $source) {
            preg_match_all('#["\'`](/api/[A-Za-z0-9_/{}%$.\-]*)#', $source, $matches);

            foreach (array_unique($matches[1]) as $literal) {
                if (in_array($literal, self::PREFIXES, true)) {
                    continue;
                }

                $candidate = preg_replace('/\$\{[^}]*\}|\{[^}]*\}|%[ds]|\$[A-Za-z_]+/', 'X', $literal);
                $candidate = str_ends_with((string) $candidate, '/') ? $candidate.'X' : $candidate;

                // A prefix with the logical path interpolated: /api/v1${endpoint}.
                if (in_array($candidate, ['/api/v1X', '/apiX'], true)) {
                    continue;
                }

                $served = array_filter($routes, fn (string $pattern): bool => preg_match($pattern, (string) $candidate) === 1);

                if ($served === []) {
                    $unknown[] = $path.': '.$literal;
                }
            }
        }

        $this->assertSame([], $unknown, 'These SDK paths are not served by the API.');
    }

    /** @return array<string, string> */
    private function sources(): array
    {
        $found = [];

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(self::SDKS, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($files as $file) {
            if (preg_match('/\.(dart|cs|go|java|kt|rb|rs|swift|ts|php|py)$/', $file->getFilename()) !== 1
                || str_contains($file->getFilename(), 'generated')) {
                continue;
            }

            $found[str_replace('\\', '/', substr($file->getPathname(), strlen(self::SDKS) + 1))] = (string) file_get_contents($file->getPathname());
        }

        return $found;
    }
}
