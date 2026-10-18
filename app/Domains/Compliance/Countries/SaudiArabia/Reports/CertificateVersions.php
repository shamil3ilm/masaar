<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\SaudiArabia\Reports;

use App\Domains\Compliance\Countries\SaudiArabia\Models\CertificateVersion;
use App\Domains\Organization\Models\Organization;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * The certificate versions devices have held, for the screens that show them.
 */
class CertificateVersions
{
    /**
     * Every device's versions across the platform, for the console, with the
     * organization's name on each.
     */
    public function all(int $perPage = 50): LengthAwarePaginator
    {
        $versions = CertificateVersion::withoutTenantScope(fn () => CertificateVersion::query()
            ->orderBy('org_id')->orderBy('device_id')->orderByDesc('version')
            ->paginate($perPage));

        $names = Organization::query()->whereIn('id', $versions->pluck('org_id')->unique())->pluck('name', 'id');

        $versions->getCollection()->each(
            fn (CertificateVersion $version) => $version->setAttribute('organization_name', $names[$version->org_id] ?? $version->org_id)
        );

        return $versions;
    }

    /**
     * One version, wherever it belongs.
     */
    public function find(int $id): CertificateVersion
    {
        return CertificateVersion::withoutTenantScope(fn () => CertificateVersion::query()->findOrFail($id));
    }
}
