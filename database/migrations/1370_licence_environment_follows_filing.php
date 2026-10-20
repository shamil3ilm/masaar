<?php

declare(strict_types=1);

use App\Domains\Licensing\Enums\LicenseEnvironment;
use App\Domains\Licensing\Models\License;
use App\Domains\Licensing\Services\LicenceEnvironments;
use App\Domains\Organization\Models\Organization;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Licences issued from the console were never given an environment, so
 * each one is a sandbox licence, refused when it files on a deployment that
 * files live. Where the deployment files live for a licence's organization
 * (LicenceEnvironments), the licence becomes a production one; elsewhere it
 * is left as it is. Each change is in the licence's audit trail, by the
 * platform.
 *
 * The key a partner holds is unchanged: it keeps its cp_test_ prefix, which
 * only ever named the environment it was issued in. The copy validation
 * caches is dropped, as the model's own saves drop it.
 */
return new class extends Migration
{
    private const REASON = 'The deployment files live: a licence issued without an environment becomes a production one.';

    private const REVERSED = 'The migration that made it a production licence was rolled back.';

    public function up(): void
    {
        $environments = app(LicenceEnvironments::class);

        DB::table('licenses')
            ->where('environment', LicenseEnvironment::Sandbox->value)
            ->orderBy('id')
            ->select(['id', 'org_id'])
            ->chunk(500, function ($licences) use ($environments): void {
                foreach ($licences as $licence) {
                    $organization = $licence->org_id !== null ? Organization::query()->find($licence->org_id) : null;

                    if ($environments->defaultFor($organization) === LicenseEnvironment::Production) {
                        $this->change($licence->id, LicenseEnvironment::Sandbox, LicenseEnvironment::Production, self::REASON);
                    }
                }
            });
    }

    /** Back to sandbox, the licences this moved and nothing has moved since. */
    public function down(): void
    {
        $moved = DB::table('license_audit_logs')
            ->where('event', 'environment_changed')
            ->where('reason', self::REASON)
            ->pluck('license_id')
            ->unique();

        foreach ($moved as $licenceId) {
            $current = DB::table('licenses')->where('id', $licenceId)->value('environment');

            if ($current === LicenseEnvironment::Production->value) {
                $this->change((string) $licenceId, LicenseEnvironment::Production, LicenseEnvironment::Sandbox, self::REVERSED);
            }
        }
    }

    private function change(string $licenceId, LicenseEnvironment $from, LicenseEnvironment $to, string $reason): void
    {
        DB::transaction(function () use ($licenceId, $from, $to, $reason): void {
            DB::table('licenses')->where('id', $licenceId)->update(['environment' => $to->value, 'updated_at' => now()]);

            DB::table('license_audit_logs')->insert([
                'id' => (string) Str::uuid(),
                'license_id' => $licenceId,
                'event' => 'environment_changed',
                'actor_type' => 'system',
                'actor_id' => null,
                'old_values' => json_encode(['environment' => $from->value]),
                'new_values' => json_encode(['environment' => $to->value]),
                'reason' => $reason,
                'created_at' => now(),
            ]);
        });

        $apiKey = DB::table('licenses')->where('id', $licenceId)->value('api_key');

        if (is_string($apiKey)) {
            Cache::forget(License::cacheKeyFor($apiKey));
        }
    }
};
