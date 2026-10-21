<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\SaudiArabia\Reports;

use App\Domains\Compliance\Countries\SaudiArabia\Filing\UnfiledDocuments;
use App\Domains\Compliance\Countries\SaudiArabia\Models\CertificateVersion;
use App\Domains\Organization\Models\EgsUnit;
use App\Domains\Organization\Models\Organization;
use App\Domains\Organization\Services\Tills;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * The certificate versions devices have held, for the screens that show them.
 */
class CertificateVersions
{
    public function __construct(
        private readonly UnfiledDocuments $unfiled,
        private readonly Tills $tills,
    ) {}

    /**
     * An organization's tills (EGS units) - head office's first - each with
     * where it stands and every certificate it has held, newest first, and
     * how many documents still wait to be filed under each. A retired till
     * that never held a certificate is left out.
     *
     * @return list<array{device: string, name: string, head_office: bool, status: string, status_label: string, onboarding: string, ready: bool, expires: ?string, issues: bool, paused: bool, versions: list<array{version: int, status: string, serial: ?string, issuer: ?string, valid_from: ?string, valid_to: ?string, waiting: int}>}>
     */
    public function forOrganization(Organization $organization): array
    {
        $organizationId = (string) $organization->id;
        $waiting = $this->unfiled->countsFor($organizationId);

        $versions = CertificateVersion::withoutTenantScope(fn () => CertificateVersion::query()
            ->where('org_id', $organizationId)
            ->orderBy('device_id')->orderByDesc('version')
            ->get())
            ->groupBy('device_id');

        return $this->tills->all($organizationId)
            ->filter(fn (EgsUnit $unit): bool => $unit->files() || $versions->has((string) $unit->id))
            ->map(fn (EgsUnit $unit): array => [
                'device' => (string) $unit->id,
                'name' => $this->tills->label($unit),
                'head_office' => $unit->isHeadOffice(),
                'status' => $unit->status,
                'status_label' => $unit->statusLabel(),
                'onboarding' => $unit->onboarding_status,
                'ready' => $unit->isHeadOffice() ? (bool) $organization->zatca_onboarded : $unit->isReady(),
                'expires' => $unit->cert_expires_at?->format('Y-m-d'),
                'issues' => $unit->issues(),
                // ZATCA refused its credentials; nothing is filed until renewed (FilingPause).
                'paused' => $unit->filing_paused_at !== null,
                'versions' => collect($versions->get((string) $unit->id, []))->map(fn (CertificateVersion $version): array => [
                    'version' => $version->version,
                    'status' => $version->status,
                    'serial' => $version->serial_number,
                    'issuer' => $version->issuer,
                    'valid_from' => $version->valid_from?->format('Y-m-d'),
                    'valid_to' => $version->valid_to?->format('Y-m-d'),
                    'waiting' => $waiting[(string) $unit->id][$version->certificate_id] ?? 0,
                ])->values()->all(),
            ])
            ->values()
            ->all();
    }

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
