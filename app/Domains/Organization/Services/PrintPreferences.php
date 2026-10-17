<?php

declare(strict_types=1);

namespace App\Domains\Organization\Services;

use App\Domains\Invoice\Enums\PaperSize;
use App\Domains\Organization\Models\Branch;
use App\Domains\Organization\Models\Organization;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Which paper an invoice is printed on.
 *
 * Four layers, the nearer winning: the paper chosen on the print screen
 * itself, the person's own (they print at a till with a roll on it), the
 * branch's (the till's printer), and the organization's. With none, A4.
 *
 * A column per layer - organizations, branches, the organization_user
 * membership - rather than a settings store, which the platform does not
 * have yet. Each layer is read and written here and nowhere else, so a
 * settings store can take them over by changing this class alone.
 */
class PrintPreferences
{
    public function paper(Organization $organization, ?string $branchId, ?string $userId, ?string $requested = null): PaperSize
    {
        return PaperSize::tryFrom((string) $requested)
            ?? ($userId === null ? null : $this->userPaper((string) $organization->id, $userId))
            ?? ($branchId === null ? null : $this->branchPaper((string) $organization->id, $branchId))
            ?? $this->organizationPaper($organization);
    }

    public function organizationPaper(Organization $organization): PaperSize
    {
        return PaperSize::tryFrom((string) $organization->paper_size) ?? PaperSize::A4;
    }

    public function setOrganizationPaper(Organization $organization, PaperSize $paper): void
    {
        $organization->forceFill(['paper_size' => $paper->value])->save();
    }

    /** The branch's own paper; null when it prints on the organization's. */
    public function branchPaper(string $organizationId, string $branchId): ?PaperSize
    {
        return PaperSize::tryFrom((string) $this->branch($organizationId, $branchId)?->paper_size);
    }

    public function setBranchPaper(string $organizationId, string $branchId, ?PaperSize $paper): void
    {
        $this->branch($organizationId, $branchId)?->forceFill(['paper_size' => $paper?->value])->save();
    }

    /** The person's own paper in this organization; null when they have none. */
    public function userPaper(string $organizationId, string $userId): ?PaperSize
    {
        return PaperSize::tryFrom((string) $this->membership($organizationId, $userId)->value('paper_size'));
    }

    public function setUserPaper(string $organizationId, string $userId, ?PaperSize $paper): void
    {
        $this->membership($organizationId, $userId)->update(['paper_size' => $paper?->value, 'updated_at' => now()]);
    }

    /**
     * The organization's branches with their own paper, for its settings.
     *
     * @return list<array{id: string, name: string, paper: PaperSize|null}>
     */
    public function branches(string $organizationId): array
    {
        return Branch::withoutTenantScope(fn () => Branch::query()->where('org_id', $organizationId)->orderBy('name')->get())
            ->map(fn (Branch $branch): array => [
                'id' => (string) $branch->id,
                'name' => (string) $branch->name,
                'paper' => PaperSize::tryFrom((string) $branch->paper_size),
            ])
            ->values()
            ->all();
    }

    private function branch(string $organizationId, string $branchId): ?Branch
    {
        // Named by organization as well as id: a branch id from another
        // organization finds nothing.
        return Branch::withoutTenantScope(fn () => Branch::query()->where('org_id', $organizationId)->whereKey($branchId)->first());
    }

    /**
     * The person's membership: the organization_user row, which is where a
     * person's standing in one organization is kept.
     */
    private function membership(string $organizationId, string $userId): Builder
    {
        return DB::table('organization_user')->where('org_id', $organizationId)->where('user_id', $userId);
    }
}
