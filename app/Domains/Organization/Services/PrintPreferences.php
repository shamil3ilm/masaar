<?php

declare(strict_types=1);

namespace App\Domains\Organization\Services;

use App\Domains\Invoice\Enums\PaperSize;
use App\Domains\Organization\Models\Branch;
use App\Domains\Organization\Models\Organization;
use App\Domains\Platform\Customization\Customizations;
use App\Domains\Platform\Customization\Level;
use App\Domains\Platform\Customization\Scope;

/**
 * Which paper an invoice is printed on.
 *
 * The paper chosen on the print screen itself wins; after it the
 * printing.paper customization decides, the nearer level winning: the
 * person's own (they print at a till with a roll on it), the branch's (the
 * till's printer), the organization's, then the platform's. With none, A4.
 *
 * A branch's receipt printer is two more customizations, printing.receipt_dpi
 * and printing.receipt_dots, null for the common printer.
 */
class PrintPreferences
{
    private const PAPER = 'printing.paper';

    private const DPI = 'printing.receipt_dpi';

    private const DOTS = 'printing.receipt_dots';

    public function __construct(
        private readonly Customizations $customizations,
    ) {}

    public function paper(Organization $organization, ?string $branchId, ?string $userId, ?string $requested = null): PaperSize
    {
        $organizationId = (string) $organization->id;
        $branchId = $branchId === null || $this->branch($organizationId, $branchId) === null ? null : $branchId;
        $scope = match (true) {
            $userId !== null => Scope::member($organizationId, $userId, $branchId),
            $branchId !== null => Scope::branch($organizationId, $branchId),
            default => Scope::organization($organizationId),
        };

        return PaperSize::tryFrom((string) $requested)
            ?? PaperSize::tryFrom((string) $this->customizations->get(self::PAPER, $scope))
            ?? PaperSize::A4;
    }

    public function organizationPaper(Organization $organization): PaperSize
    {
        return PaperSize::tryFrom((string) $this->customizations->get(self::PAPER, Scope::organization((string) $organization->id))) ?? PaperSize::A4;
    }

    public function setOrganizationPaper(Organization $organization, PaperSize $paper, ?string $actorId = null): void
    {
        $this->customizations->set(self::PAPER, Level::Organization, Scope::organization((string) $organization->id), $paper->value, $actorId);
    }

    /** The branch's own paper; null when it prints on the organization's. */
    public function branchPaper(string $organizationId, string $branchId): ?PaperSize
    {
        return $this->branch($organizationId, $branchId) === null
            ? null
            : PaperSize::tryFrom((string) $this->customizations->own(self::PAPER, Level::Branch, Scope::branch($organizationId, $branchId)));
    }

    public function setBranchPaper(string $organizationId, string $branchId, ?PaperSize $paper, ?string $actorId = null): void
    {
        if ($this->branch($organizationId, $branchId) !== null) {
            $this->put(self::PAPER, Level::Branch, Scope::branch($organizationId, $branchId), $paper?->value, $actorId);
        }
    }

    /**
     * The branch's receipt printer, as far as it differs from the common
     * one: its resolution and the dots across its head, each null when it is
     * the common printer's.
     *
     * @return array{dpi: int|null, dots: int|null}
     */
    public function receiptPrinter(string $organizationId, ?string $branchId): array
    {
        $scope = $branchId === null || $this->branch($organizationId, $branchId) === null
            ? Scope::organization($organizationId)
            : Scope::branch($organizationId, $branchId);

        return [
            'dpi' => $this->customizations->get(self::DPI, $scope),
            'dots' => $this->customizations->get(self::DOTS, $scope),
        ];
    }

    public function setReceiptPrinter(string $organizationId, string $branchId, ?int $dpi, ?int $dots, ?string $actorId = null): void
    {
        if ($this->branch($organizationId, $branchId) === null) {
            return;
        }

        $scope = Scope::branch($organizationId, $branchId);
        $this->put(self::DPI, Level::Branch, $scope, $dpi, $actorId);
        $this->put(self::DOTS, Level::Branch, $scope, $dots, $actorId);
    }

    /** The person's own paper in this organization; null when they have none. */
    public function userPaper(string $organizationId, string $userId): ?PaperSize
    {
        return PaperSize::tryFrom((string) $this->customizations->own(self::PAPER, Level::User, Scope::member($organizationId, $userId)));
    }

    public function setUserPaper(string $organizationId, string $userId, ?PaperSize $paper): void
    {
        $this->put(self::PAPER, Level::User, Scope::member($organizationId, $userId), $paper?->value, $userId);
    }

    /**
     * The organization's branches with their own paper, for its settings.
     *
     * @return list<array{id: string, name: string, paper: PaperSize|null, dpi: int|null, dots: int|null}>
     */
    public function branches(string $organizationId): array
    {
        return Branch::withoutTenantScope(fn () => Branch::query()->where('org_id', $organizationId)->orderBy('name')->get())
            ->map(function (Branch $branch) use ($organizationId): array {
                $scope = Scope::branch($organizationId, (string) $branch->id);

                return [
                    'id' => (string) $branch->id,
                    'name' => (string) $branch->name,
                    'paper' => PaperSize::tryFrom((string) $this->customizations->own(self::PAPER, Level::Branch, $scope)),
                    'dpi' => $this->customizations->own(self::DPI, Level::Branch, $scope),
                    'dots' => $this->customizations->own(self::DOTS, Level::Branch, $scope),
                ];
            })
            ->values()
            ->all();
    }

    private function branch(string $organizationId, string $branchId): ?Branch
    {
        // Named by organization as well as id: a branch id from another
        // organization finds nothing.
        return Branch::withoutTenantScope(fn () => Branch::query()->where('org_id', $organizationId)->whereKey($branchId)->first());
    }

    /** Set a level's value, or with null leave it to the level above. */
    private function put(string $key, Level $level, Scope $scope, mixed $value, ?string $actorId): void
    {
        $value === null
            ? $this->customizations->reset($key, $level, $scope, $actorId)
            : $this->customizations->set($key, $level, $scope, $value, $actorId);
    }
}
