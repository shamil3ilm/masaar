<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Services;

use App\Domains\Compliance\Engine\Contracts\TaxReports;
use App\Domains\Organization\Services\BranchScope;
use App\Domains\Organization\Services\BranchService;
use Carbon\CarbonImmutable;

/**
 * A period's figures branch by branch, beside the combined report: each
 * branch's documents, net amount and tax as its country's report counts
 * them (TaxReports::totals), and head office's own - what names no branch.
 *
 * Only for a combined view: a report of one branch is its own breakdown.
 * Only the branches the person may see, so a cashier with two branches sees
 * those two and no head office.
 */
class BranchBreakdown
{
    public function __construct(
        private readonly BranchScope $scope,
        private readonly BranchService $branches,
    ) {}

    /**
     * @return list<array{id: string, name: string, documents: int, base: float, vat: float, currency: string}>
     */
    public function rows(string $organizationId, TaxReports $reports, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $visible = $this->scope->visible();

        if ($visible !== null && count($visible) < 2) {
            return [];
        }

        $rows = [];

        foreach ($this->branches->tills($organizationId) as $till) {
            if ($visible === null || in_array($till['id'], $visible, true)) {
                $rows[] = $this->row($till['id'], $till['name'], $organizationId, $reports, $from, $to);
            }
        }

        if ($rows === []) {
            return [];
        }

        // Everything the person sees includes head office's own invoices.
        if ($visible === null) {
            $rows[] = $this->row(BranchScope::NONE, __('Head office'), $organizationId, $reports, $from, $to);
        }

        return $rows;
    }

    /**
     * @return array{id: string, name: string, documents: int, base: float, vat: float, currency: string}
     */
    private function row(string $id, string $name, string $organizationId, TaxReports $reports, CarbonImmutable $from, CarbonImmutable $to): array
    {
        return [
            'id' => $id,
            'name' => $name,
            ...$this->scope->within([$id], fn (): array => $reports->totals($organizationId, $from, $to)),
        ];
    }
}
