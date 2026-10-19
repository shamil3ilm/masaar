<?php

declare(strict_types=1);

namespace App\Domains\Platform\Services;

use App\Domains\Compliance\Engine\Services\JurisdictionRegistry;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Whether this instance can serve a request.
 *
 * Only what every request needs: the database, and the cache that sessions,
 * rate limits and locks live in. ZATCA is deliberately absent - when it is down
 * invoices queue offline and the platform keeps serving, so taking an instance
 * out of rotation for it would turn a degraded service into no service. The
 * queue backlog and the circuit breaker are for /api/metrics and the alerts.
 *
 * In production, also each country that is on: a configuration that cannot
 * work there (Jurisdiction::readinessProblems) keeps a new instance out of
 * rotation instead of serving wrong. Which country, and why, goes to the log.
 */
class Readiness
{
    public function __construct(
        private readonly Application $app,
        private readonly JurisdictionRegistry $jurisdictions,
    ) {}

    /**
     * @return array{database: bool, cache: bool, countries?: bool}
     */
    public function check(): array
    {
        $checks = [
            'database' => $this->database(),
            'cache' => $this->cache(),
        ];

        if ($this->app->environment('production')) {
            $checks['countries'] = $this->countries();
        }

        return $checks;
    }

    private function countries(): bool
    {
        $ready = true;

        try {
            foreach ($this->jurisdictions->enabled() as $code) {
                $problems = $this->jurisdictions->get($code)->readinessProblems();

                if ($problems !== []) {
                    Log::warning('Readiness: a country that is on cannot serve in production', ['country' => $code, 'problems' => $problems]);
                    $ready = false;
                }
            }
        } catch (\Throwable $e) {
            Log::warning('Readiness: the countries could not be checked', ['error' => $e->getMessage()]);

            return false;
        }

        return $ready;
    }

    private function database(): bool
    {
        try {
            DB::select('SELECT 1');

            return true;
        } catch (\Throwable $e) {
            Log::warning('Readiness: the database is not answering', ['error' => $e->getMessage()]);

            return false;
        }
    }

    private function cache(): bool
    {
        try {
            $key = 'readiness:'.Str::random(16);
            Cache::put($key, true, 10);
            $answered = Cache::get($key) === true;
            Cache::forget($key);

            if (! $answered) {
                Log::warning('Readiness: the cache accepted a write and returned nothing');
            }

            return $answered;
        } catch (\Throwable $e) {
            Log::warning('Readiness: the cache is not answering', ['error' => $e->getMessage()]);

            return false;
        }
    }
}
