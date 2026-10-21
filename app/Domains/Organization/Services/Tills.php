<?php

declare(strict_types=1);

namespace App\Domains\Organization\Services;

use App\Domains\Organization\Models\Branch;
use App\Domains\Organization\Models\EgsUnit;
use App\Support\Brand;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * An organization's tills (EGS units): which one a document is issued on,
 * and the tills a branch has.
 *
 * Head office's unit is the organization's own id, and what a branch with
 * no till of its own issues on. A branch's first till has the branch's id;
 * one added later an id of its own. A document names its till by device_id.
 */
class Tills
{
    /** One of the organization's tills, a retired one included; null when it is not one of them. */
    public function find(string $organizationId, string $unitId): ?EgsUnit
    {
        return EgsUnit::withoutTenantScope(fn (): ?EgsUnit => EgsUnit::query()
            ->whereKey($unitId)
            ->where('org_id', $organizationId)
            ->first());
    }

    /**
     * Head office's own unit. Every organization is given one when it is
     * created (Organization::booted); one made before that is given it here.
     */
    public function headOffice(string $organizationId): EgsUnit
    {
        $unit = $this->find($organizationId, $organizationId);

        if ($unit !== null) {
            return $unit;
        }

        // An insert that does nothing when a concurrent request made it first.
        EgsUnit::withoutTenantScope(fn () => EgsUnit::query()->insertOrIgnore([
            'id' => $organizationId,
            'org_id' => $organizationId,
            'host' => EgsUnit::HOST_CLOUD,
            'status' => EgsUnit::ACTIVE,
            'onboarding_status' => Branch::STATUS_PENDING,
            'created_at' => now(),
            'updated_at' => now(),
        ]));

        return $this->find($organizationId, $organizationId) ?? throw new DomainException('Head office\'s unit could not be created.');
    }

    /**
     * The till a document of this branch is issued on when it names none:
     * the branch's lowest-numbered till that still issues - one that is
     * ready first - else head office's, which is also where a document of
     * no branch is issued.
     */
    public function defaultFor(string $organizationId, ?string $branchId): EgsUnit
    {
        if ($branchId !== null) {
            $issuing = $this->forBranch($organizationId, $branchId)->filter(fn (EgsUnit $unit): bool => $unit->issues());
            // One that is connected and current first, so a branch whose first
            // till is still being onboarded issues on one that is ready.
            $unit = $issuing->first(fn (EgsUnit $unit): bool => $unit->isReady()) ?? $issuing->first();

            if ($unit !== null) {
                return $unit;
            }
        }

        return $this->headOffice($organizationId);
    }

    /**
     * The till a request names - by its id, or by its number within the
     * branch it names - checked to be the organization's, of that branch,
     * and still issuing; with the branch the invoice is then for. Naming no
     * till leaves it to be chosen at issue (defaultFor).
     *
     * @return array{branch: ?string, till: ?string}
     *
     * @throws DomainException saying why the till named cannot issue it
     */
    public function named(string $organizationId, ?string $branchId, ?string $tillId, ?int $tillNumber): array
    {
        if ($tillId === null && $tillNumber === null) {
            return ['branch' => $branchId, 'till' => null];
        }

        $unit = $tillId !== null
            ? $this->find($organizationId, $tillId)
            : $this->byNumber($organizationId, $branchId, $tillNumber);

        if ($unit === null) {
            throw new DomainException($tillId !== null
                ? __('Till :till is not one of this organization\'s.', ['till' => $tillId])
                : __('There is no till :number here.', ['number' => $tillNumber]));
        }

        if ($branchId !== null && $unit->branch_id !== null && $unit->branch_id !== $branchId) {
            throw new DomainException(__(':till is not one of this branch\'s tills.', ['till' => $this->label($unit)]));
        }

        if (! $unit->issues()) {
            throw new DomainException(__('Till :till no longer issues invoices (:status). Issue from another till.', [
                'till' => $this->label($unit),
                'status' => $unit->statusLabel(),
            ]));
        }

        return ['branch' => $branchId ?? $unit->branch_id, 'till' => (string) $unit->id];
    }

    /**
     * A branch's tills in till order, retired ones included.
     *
     * @return Collection<int, EgsUnit>
     */
    public function forBranch(string $organizationId, string $branchId): Collection
    {
        return EgsUnit::withoutTenantScope(fn () => EgsUnit::query()
            ->where('org_id', $organizationId)
            ->where('branch_id', $branchId)
            ->orderBy('till_number')
            ->get());
    }

    /**
     * Every till of the organization: head office's first, then in till order.
     *
     * @return Collection<int, EgsUnit>
     */
    public function all(string $organizationId): Collection
    {
        $this->headOffice($organizationId);

        return EgsUnit::withoutTenantScope(fn () => EgsUnit::query()
            ->with('branch:id,name')
            ->where('org_id', $organizationId)
            ->orderByRaw('case when branch_id is null then 0 else 1 end')
            ->orderBy('till_number')
            ->get());
    }

    /**
     * A till by its number, within a branch when one is named.
     */
    public function byNumber(string $organizationId, ?string $branchId, int $number): ?EgsUnit
    {
        return EgsUnit::withoutTenantScope(fn (): ?EgsUnit => EgsUnit::query()
            ->where('org_id', $organizationId)
            ->where('till_number', $number)
            ->when($branchId !== null, fn ($query) => $query->where('branch_id', $branchId))
            ->first());
    }

    /**
     * The tills that number from a series of their own, for the numbering
     * screen: every till but head office's that is not retired, with its
     * number and a name - its own beside its branch's, or its branch's.
     *
     * @return list<array{id: string, name: string, till_number: int|null}>
     */
    public function series(string $organizationId): array
    {
        return $this->all($organizationId)
            ->filter(fn (EgsUnit $unit): bool => ! $unit->isHeadOffice() && $unit->files())
            ->map(fn (EgsUnit $unit): array => [
                'id' => (string) $unit->id,
                'name' => filled($unit->name) ? $unit->name.' · '.$unit->branch?->name : (string) $unit->branch?->name,
                'till_number' => $unit->till_number,
            ])
            ->values()
            ->all();
    }

    /**
     * Each till's name as a list shows it - "Till 2 · Riyadh" - by id, head
     * office's included.
     *
     * @return array<string, string>
     */
    public function labels(string $organizationId): array
    {
        return $this->all($organizationId)
            ->mapWithKeys(fn (EgsUnit $unit): array => [(string) $unit->id => $this->label($unit)])
            ->all();
    }

    /** A till's name with its branch's, as lists and reports show it. */
    public function label(EgsUnit $unit): string
    {
        if ($unit->isHeadOffice() || $unit->branch === null) {
            return $unit->label();
        }

        return filled($unit->name)
            ? $unit->name.' · '.$unit->branch->name
            : __('Till :number · :name', ['number' => $unit->till_number, 'name' => $unit->branch->name]);
    }

    /**
     * A till's number, or a branch's when the id is a branch's - every branch
     * has a first till under its own id, so the two agree; null for none.
     */
    public function tillNumber(string $id): ?int
    {
        return EgsUnit::withoutTenantScope(fn (): ?int => EgsUnit::query()->whereKey($id)->value('till_number'))
            ?? Branch::withoutTenantScope(fn (): ?int => Branch::withTrashed()->whereKey($id)->value('till_number'));
    }

    /**
     * The branch a till is in, or the branch itself when the id is a
     * branch's; null for head office's or an id that is neither.
     */
    public function branchOf(string $id): ?string
    {
        $unit = EgsUnit::withoutTenantScope(fn (): ?EgsUnit => EgsUnit::query()->whereKey($id)->first(['id', 'branch_id']));

        if ($unit !== null) {
            return $unit->branch_id;
        }

        return Branch::withoutTenantScope(fn (): bool => Branch::withTrashed()->whereKey($id)->exists()) ? $id : null;
    }

    /**
     * Another till for a branch, with the organization's next number. Its
     * serial is the solution's and its own id, as ZATCA's CSR asks for
     * (1-<solution>|2-<model or version>|3-<serial>), so it identifies this
     * till to ZATCA from the moment it is onboarded.
     */
    public function add(Branch $branch, ?string $name = null): EgsUnit
    {
        return DB::transaction(function () use ($branch, $name): EgsUnit {
            // Numbers are read and then taken: one at a time per organization.
            Branch::lockOrganization((string) $branch->org_id);

            $unit = new EgsUnit([
                'org_id' => $branch->org_id,
                'branch_id' => $branch->id,
                'name' => filled($name) ? $name : null,
            ]);
            $unit->id = (string) $unit->newUniqueId();
            $unit->egs_serial = self::serialFor((string) $unit->id);

            EgsUnit::withoutTenantScope(fn () => $unit->save());

            return $unit;
        });
    }

    /**
     * Every till of the organization back to not onboarded, its certificate
     * date cleared, for a filing system that has to onboard them all again.
     * Each keeps its serial, so it is onboarded as the same unit.
     */
    public function resetOnboarding(string $organizationId): void
    {
        $pending = ['onboarding_status' => Branch::STATUS_PENDING, 'cert_expires_at' => null];

        EgsUnit::withoutTenantScope(fn () => EgsUnit::query()->where('org_id', $organizationId)->update($pending));
        Branch::withoutTenantScope(fn () => Branch::query()->where('org_id', $organizationId)->update([...$pending, 'onboarded_at' => null]));
    }

    /**
     * Stop a till filing, with the reason. True when it was filing until now,
     * so the caller tells people once.
     */
    public function pauseFiling(EgsUnit $unit, string $reason): bool
    {
        return EgsUnit::withoutTenantScope(fn (): int => EgsUnit::query()
            ->whereKey($unit->getKey())
            ->whereNull('filing_paused_at')
            ->update(['filing_paused_at' => now(), 'filing_paused_reason' => mb_substr($reason, 0, 255)])) === 1;
    }

    /**
     * Let a paused till file again. True when it was paused.
     */
    public function resumeFiling(string $organizationId, string $unitId): bool
    {
        return EgsUnit::withoutTenantScope(fn (): int => EgsUnit::query()
            ->whereKey($unitId)
            ->where('org_id', $organizationId)
            ->whereNotNull('filing_paused_at')
            ->update(['filing_paused_at' => null, 'filing_paused_reason' => null])) === 1;
    }

    /**
     * The organization's tills whose filing is paused.
     *
     * @return Collection<int, EgsUnit>
     */
    public function paused(string $organizationId): Collection
    {
        return EgsUnit::withoutTenantScope(fn () => EgsUnit::query()
            ->where('org_id', $organizationId)
            ->whereNotNull('filing_paused_at')
            ->get());
    }

    /**
     * The EGS serial a new unit is onboarded under: the solution, its
     * version, and the unit's own id.
     */
    public static function serialFor(string $unitId): string
    {
        return '1-'.str(Brand::csrSolution())->slug().'|2-1.0|3-'.$unitId;
    }
}
