<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Engine\Contracts;

use App\Domains\Organization\Models\Organization;

/**
 * A country's rules for the seller: what its details have to be, which of
 * them sign-up and the company page ask for, and where they are kept.
 *
 * Shared code - sign-up, the company page and the API's organization
 * routes - checks the name and the country itself (CompanyProfileRules) and
 * asks the organization's country for everything else, so no caller has to
 * know which shapes apply where.
 */
interface SellerRules
{
    /**
     * The seller's details beyond its names, in the country's shapes.
     *
     * @param  bool  $complete  Every field required, as the company page and
     *                          sign-up ask for them. Otherwise each is
     *                          checked only when sent, as the API allows.
     * @param  Organization|null  $existing  The organization being changed.
     * @return array<string, list<mixed>>
     */
    public function rules(bool $complete, ?Organization $existing = null): array;

    /**
     * The messages for rules(), by rule key.
     *
     * @return array<string, string>
     */
    public function messages(): array;

    /**
     * The fields sign-up asks for beyond the company's names: the keys of
     * rules() its form section sends.
     *
     * @return list<string>
     */
    public function signupFields(): array;

    /** The Blade view of sign-up's section for the country, given no variables. */
    public function signupView(): string;

    /** The Blade view of the company page's form, given $organization and companyData(). */
    public function companyView(): string;

    /**
     * What companyView() shows beside the organization's own columns.
     *
     * @return array<string, mixed>
     */
    public function companyData(Organization $organization): array;

    /**
     * The VAT number the organization is registered under, from details
     * rules() validated; null when it has none.
     *
     * @param  array<string, mixed>  $details
     */
    public function vatNumber(array $details): ?string;

    /**
     * Save details rules() validated, from the company page or the API: the
     * organization's own columns, and whatever the country keeps elsewhere.
     * A `prices_include_vat` among them is saved too.
     *
     * @param  array<string, mixed>  $details
     */
    public function save(Organization $organization, array $details): void;

    /**
     * After an organization is registered with its names, VAT number and
     * address, keep what else the country stores from the same details.
     *
     * @param  array<string, mixed>  $details
     */
    public function registered(Organization $organization, array $details): void;

    /** Whether every detail the country's documents need from the seller is there. */
    public function complete(Organization $organization): bool;
}
