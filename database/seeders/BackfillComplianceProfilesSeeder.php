<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domains\Organization\Models\ComplianceProfile;
use App\Domains\Organization\Models\Organization;
use Illuminate\Database\Seeder;

/**
 * One-time backfill: converts legacy organizations.compliance_profile JSON
 * into proper compliance_profiles rows.
 *
 * Safe to run multiple times (idempotent via firstOrCreate).
 * Run via: php artisan db:seed --class=BackfillComplianceProfilesSeeder
 */
class BackfillComplianceProfilesSeeder extends Seeder
{
    /**
     * Engine map: ISO country code → engine slug.
     */
    private const ENGINE_MAP = [
        'SA' => 'fatoora',
        'AE' => 'fta',
        'QA' => 'gta',
    ];

    public function run(): void
    {
        Organization::whereNotNull('compliance_profile')
            ->each(function (Organization $org) {
                $jurisdiction = $org->country ?? 'SA';
                $engine = self::ENGINE_MAP[$jurisdiction] ?? 'fatoora';
                $legacy = $org->compliance_profile ?? [];

                // Skip if nothing useful in the JSON
                if (empty($legacy)) {
                    return;
                }

                $isOnboarded = (bool) ($legacy['zatca_onboarded']
                    ?? $legacy['fta_onboarded']
                    ?? false);

                ComplianceProfile::firstOrCreate(
                    [
                        'org_id' => $org->id,
                        'jurisdiction' => $jurisdiction,
                    ],
                    [
                        'engine' => $engine,
                        'status' => $isOnboarded
                            ? ComplianceProfile::STATUS_ACTIVE
                            : ComplianceProfile::STATUS_PENDING,
                        'settings' => $legacy,
                    ]
                );
            });
    }
}
