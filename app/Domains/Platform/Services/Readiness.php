<?php

declare(strict_types=1);

namespace App\Domains\Platform\Services;

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
 */
class Readiness
{
    /**
     * @return array{database: bool, cache: bool}
     */
    public function check(): array
    {
        return [
            'database' => $this->database(),
            'cache' => $this->cache(),
        ];
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
