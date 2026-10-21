<?php

declare(strict_types=1);

namespace App\Domains\Books\Services;

use App\Domains\Compliance\Engine\Contracts\Jurisdiction;
use App\Domains\Compliance\Engine\Services\JurisdictionResolver;
use App\Domains\Organization\Models\Organization;

/**
 * What the books take from the organization's country, asked of its module
 * rather than named: the currency its return is in and its standard rate
 * (the first of its VAT categories, VatTreatments::categories).
 */
final class BooksCountry
{
    public function __construct(
        private readonly JurisdictionResolver $jurisdictions,
    ) {}

    /** The organization's country, switched on or not: books stay open either way. */
    public function of(Organization $organization): Jurisdiction
    {
        return $this->jurisdictions->forRules($organization);
    }

    public function currency(Organization $organization): string
    {
        return $this->of($organization)->currency();
    }

    public function standardRate(Organization $organization): string
    {
        $categories = $this->of($organization)->vatTreatments()->categories();

        return (string) ($categories[0]->rate ?? '0');
    }
}
