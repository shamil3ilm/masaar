<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Services;

use App\Domains\Auth\Models\User;
use App\Domains\Compliance\Engine\Services\JurisdictionResolver;
use App\Domains\Organization\Models\Organization;
use App\Domains\Organization\Services\BranchAccess;
use App\Domains\Organization\Services\BranchScope;
use App\Domains\Organization\Services\TenantResolver;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * A period across the companies of a group, read-only, one line each.
 *
 * Each company's figures are its own country's report of its own documents
 * (TaxReports::totals), in its own tax currency, and only of the branches
 * the person works at there - nothing is added across companies, because
 * each files on its own. Amounts are summed per currency for a glance, and
 * never converted.
 */
class GroupReport
{
    public function __construct(
        private readonly JurisdictionResolver $jurisdictions,
        private readonly BranchAccess $access,
        private readonly BranchScope $scope,
        private readonly TenantResolver $tenant,
    ) {}

    /**
     * @param  Collection<int, Organization>  $companies
     * @return list<array{id: string, name: string, country: string, restricted: bool, documents: int, base: float, vat: float, currency: string}>
     */
    public function rows(User $user, Collection $companies, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $rows = [];

        foreach ($companies as $company) {
            $jurisdiction = $this->jurisdictions->forRules($company);
            $allowed = $this->access->allowed((string) $user->id, (string) $company->id);

            $rows[] = [
                'id' => (string) $company->id,
                'name' => (string) $company->name,
                'country' => $jurisdiction->name(),
                'restricted' => $allowed !== null,
                // Read as that company, so its own tenant scope holds while
                // its documents are read, and of the person's branches there.
                ...$this->tenant->runAs((string) $company->id, fn (): array => $this->scope->within(
                    $allowed,
                    fn (): array => $jurisdiction->reports()->totals((string) $company->id, $from, $to),
                )),
            ];
        }

        return $rows;
    }

    /**
     * The rows summed per currency.
     *
     * @param  list<array{documents: int, base: float, vat: float, currency: string}>  $rows
     * @return array<string, array{documents: int, base: float, vat: float}>
     */
    public function sums(array $rows): array
    {
        $sums = [];

        foreach ($rows as $row) {
            $sum = $sums[$row['currency']] ?? ['documents' => 0, 'base' => 0.0, 'vat' => 0.0];
            $sums[$row['currency']] = [
                'documents' => $sum['documents'] + $row['documents'],
                'base' => round($sum['base'] + $row['base'], 2),
                'vat' => round($sum['vat'] + $row['vat'], 2),
            ];
        }

        ksort($sums);

        return $sums;
    }
}
