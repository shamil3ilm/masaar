<?php

declare(strict_types=1);

namespace App\Domains\Platform\Services;

use App\Domains\Compliance\Engine\Contracts\LanguageRules;
use App\Domains\Compliance\Engine\Services\JurisdictionResolver;
use App\Domains\Invoice\Services\Catalogue;
use App\Domains\Organization\Models\Organization;
use App\Domains\Organization\Services\LanguageGrace;
use Illuminate\Support\Facades\Validator;

/**
 * The Arabic names an organization still lacks where its country requires
 * them: its own, and its customers' and products' that invoices take theirs
 * from, so a platform admin can have them filled in before a grace period
 * ends or enforcement reaches a customer that has been invoicing in English.
 *
 * Each name is held to the country's own rules for it (SellerRules,
 * LanguageRules::arabicNameRules()), so the list is exactly what an invoice
 * would be refused for, and nothing here knows what Arabic looks like.
 * Archived records are left out: nothing new is invoiced from them.
 */
class ArabicNameReport
{
    public function __construct(
        private readonly JurisdictionResolver $jurisdictions,
        private readonly LanguageGrace $grace,
        private readonly Catalogue $catalogue,
    ) {}

    /**
     * For the organization's page: null where its country does not require
     * Arabic; otherwise the grace and how many names are missing.
     *
     * @return array{grace: array{until: string, reason: string, by: string|null, active: bool}|null, seller: bool, customers: int, products: int}|null
     */
    public function summary(string $organizationId): ?array
    {
        $missing = $this->missing($organizationId);

        if ($missing === null) {
            return null;
        }

        return [
            'grace' => $this->grace->describe($missing['organization']),
            'seller' => $missing['seller'],
            'customers' => count($missing['customers']),
            'products' => count($missing['products']),
        ];
    }

    /**
     * Every name missing, for the list page; null where the country does not
     * require Arabic or there is no such organization.
     *
     * @return array{organization: Organization, seller: bool, customers: list<object>, products: list<object>}|null
     */
    public function missing(string $organizationId): ?array
    {
        $organization = Organization::query()->find($organizationId);

        if ($organization === null) {
            return null;
        }

        $jurisdiction = $this->jurisdictions->forRules($organization);
        $language = $jurisdiction->languageRules();

        if (! $language->requiresArabic()) {
            return null;
        }

        $seller = ['name_ar' => $jurisdiction->sellerRules()->rules(true, $organization)['name_ar'] ?? ['nullable']];

        return [
            'organization' => $organization,
            'seller' => ! $this->passes($organization->name_ar, $seller['name_ar']),
            'customers' => $this->lacking($this->catalogue->customerChoices($organizationId), $language->arabicNameRules(LanguageRules::CUSTOMER)),
            'products' => $this->lacking($this->catalogue->productChoices($organizationId), $language->arabicNameRules(LanguageRules::PRODUCT)),
        ];
    }

    /**
     * The customers or products whose Arabic name the rules refuse.
     *
     * @param  iterable<object>  $records
     * @param  list<mixed>  $rules
     * @return list<object>
     */
    private function lacking(iterable $records, array $rules): array
    {
        $lacking = [];

        foreach ($records as $record) {
            if (! $this->passes($record->name_ar, $rules)) {
                $lacking[] = $record;
            }
        }

        return $lacking;
    }

    /**
     * @param  list<mixed>  $rules
     */
    private function passes(mixed $name, array $rules): bool
    {
        return Validator::make(['name_ar' => $name], ['name_ar' => $rules])->passes();
    }
}
