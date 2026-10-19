<?php

declare(strict_types=1);

namespace App\Domains\Organization\Services;

use App\Domains\Organization\Models\Branch;
use App\Domains\Organization\Models\EgsUnit;
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
     * the branch's lowest-numbered till that still issues, else head
     * office's - which is also where a document of no branch is issued.
     */
    public function defaultFor(string $organizationId, ?string $branchId): EgsUnit
    {
        if ($branchId !== null) {
            $unit = $this->forBranch($organizationId, $branchId)->first(fn (EgsUnit $unit): bool => $unit->issues());

            if ($unit !== null) {
                return $unit;
            }
        }

        return $this->headOffice($organizationId);
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
     * The EGS serial a new unit is onboarded under: the solution, its
     * version, and the unit's own id.
     */
    public static function serialFor(string $unitId): string
    {
        return '1-'.str((string) config('app.name', 'Masaar'))->slug().'|2-1.0|3-'.$unitId;
    }
}
