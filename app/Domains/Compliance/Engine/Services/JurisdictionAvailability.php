<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Engine\Services;

use App\Domains\Audit\Attribution\Origin;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Whether this deployment issues in a country: one answer, for every module.
 *
 * A platform admin's switch decides when there is one; otherwise the module's
 * configured default does (each module names its own environment variable).
 * Every module's enabled() asks here in the same way, so a switch thrown in
 * the admin console reaches every place that asks the engine - issuing, the
 * API, sign-up, the company country list and the portal's banner.
 *
 * The switches are rows in `settings`, beside the console's other stored
 * values and with the same meaning: a row overrides a default, and it records
 * who changed it and when. Settings ignores keys it does not define, so these
 * rows never reach its screen.
 *
 * Asked on every issue and every portal page, so the switches are cached as
 * one entry and forgotten on write. A switch flipped on one host is seen by
 * every other at their next read of the shared cache.
 */
final class JurisdictionAvailability
{
    private const CACHE_KEY = 'compliance.jurisdiction_switches';

    public function __construct(private readonly Origin $origin) {}

    private const PREFIX = 'jurisdiction.';

    private const SUFFIX = '.enabled';

    /**
     * The admin's switch for the country, or the module's default.
     */
    public function enabled(string $code, bool $default): bool
    {
        return $this->switched($code)['enabled'] ?? $default;
    }

    /**
     * The admin's switch for the country, when one has been thrown.
     *
     * @return array{enabled: bool, by: string|null, at: string|null}|null
     */
    public function switched(string $code): ?array
    {
        return $this->all()[$code] ?? null;
    }

    /**
     * Switch the country on or off, recording who did it.
     */
    public function set(string $code, bool $enabled, ?string $actor): void
    {
        // Who first threw the switch, and when, is kept; who last did moves.
        DB::table('settings')->upsert(
            [[
                'key' => self::PREFIX.$code.self::SUFFIX,
                'value' => json_encode($enabled),
                'created_by' => $this->origin->actorId(),
                'updated_by' => $actor,
                'created_at' => now(),
                'updated_at' => now(),
            ]],
            ['key'],
            ['value', 'updated_by', 'updated_at'],
        );

        Cache::forget(self::CACHE_KEY);
    }

    /**
     * @return array<string, array{enabled: bool, by: string|null, at: string|null}>
     */
    private function all(): array
    {
        return Cache::rememberForever(self::CACHE_KEY, fn (): array => $this->read());
    }

    /**
     * @return array<string, array{enabled: bool, by: string|null, at: string|null}>
     */
    private function read(): array
    {
        $switches = [];
        $rows = DB::table('settings')
            ->where('key', 'like', self::PREFIX.'%'.self::SUFFIX)
            ->get(['key', 'value', 'updated_by', 'updated_at']);

        foreach ($rows as $row) {
            $code = substr((string) $row->key, strlen(self::PREFIX), -strlen(self::SUFFIX));

            $switches[$code] = [
                'enabled' => (bool) json_decode((string) $row->value, true),
                'by' => $row->updated_by === null ? null : (string) $row->updated_by,
                'at' => $row->updated_at === null ? null : (string) $row->updated_at,
            ];
        }

        return $switches;
    }
}
