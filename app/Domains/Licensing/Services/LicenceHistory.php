<?php

declare(strict_types=1);

namespace App\Domains\Licensing\Services;

use App\Domains\Licensing\Enums\LicenseStatus;
use App\Domains\Licensing\Enums\LicenseTier;
use App\Domains\Licensing\Models\License;
use App\Http\Listing\CsvExport;
use App\Http\Listing\Filters\Choice;
use App\Http\Listing\Filters\DateRange;
use App\Http\Listing\Filters\Search;
use App\Http\Listing\ListSpec;
use App\Http\Listing\ListState;
use App\Http\Listing\Sort;
use Illuminate\Pagination\LengthAwarePaginator;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Every licence, as the console's history list. The old links still work:
 * status, tier and q mean what they did.
 */
class LicenceHistory
{
    public function spec(string $key): ListSpec
    {
        $statuses = [];
        $tiers = [];

        foreach (LicenseStatus::cases() as $status) {
            $statuses[$status->value] = $status->getLabel();
        }

        foreach (LicenseTier::cases() as $tier) {
            $tiers[$tier->value] = $tier->getDisplayName();
        }

        return new ListSpec(
            key: $key,
            sorts: [
                'newest' => new Sort('Newest', 'created_at'),
                'organization' => new Sort('Organization', 'organization_name', 'asc'),
                'expires' => new Sort('Expires', 'expires_at', 'asc'),
                'quota' => new Sort('Invoices / month', 'invoices_per_month'),
            ],
            defaultSort: 'newest',
            filters: [
                new Search('q', 'Search', ['organization_name', 'contact_email', 'api_key'], 'Organization, contact email or API key'),
                new Choice('status', 'Status', $statuses, 'status', 'Any status'),
                new Choice('tier', 'Tier', $tiers, 'tier', 'Any tier'),
                new DateRange('expires_at', 'Expires', instant: true),
            ],
        );
    }

    public function page(ListState $list): LengthAwarePaginator
    {
        return $list->paginate(License::query());
    }

    /** Every licence the list's filters give, as a CSV. No key or secret is in it. */
    public function csv(ListState $list, string $fileName): StreamedResponse
    {
        return CsvExport::download($fileName, [
            'Organization' => fn (License $licence): string => (string) $licence->organization_name,
            'Contact' => fn (License $licence): string => (string) $licence->contact_email,
            'Tier' => fn (License $licence): string => $licence->tier->getDisplayName(),
            'Status' => fn (License $licence): string => $licence->status->getLabel(),
            'Invoices / month' => fn (License $licence): string => (string) $licence->invoices_per_month,
            'Expires' => fn (License $licence): string => (string) $licence->expires_at?->toDateString(),
            'Issued' => fn (License $licence): string => (string) $licence->created_at?->toDateString(),
        ], $list->apply(License::query())->lazy(500));
    }
}
