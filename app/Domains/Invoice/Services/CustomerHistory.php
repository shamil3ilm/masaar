<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Services;

use App\Domains\Invoice\Models\Customer;
use App\Domains\Organization\Models\Organization;
use App\Http\Listing\CsvExport;
use App\Http\Listing\Filters\Choice;
use App\Http\Listing\Filters\DateRange;
use App\Http\Listing\Filters\Search;
use App\Http\Listing\ListSpec;
use App\Http\Listing\ListState;
use App\Http\Listing\Sort;
use App\Support\CountryClock;
use Illuminate\Contracts\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * An organization's customers as a history list.
 *
 * Active customers unless the list asks for the archived ones, as the page
 * always showed - `archived=1` still means what it did - or the removed
 * ones, to bring back (CatalogueRemoval). Every query names the
 * organization, as Catalogue's do.
 */
class CustomerHistory
{
    /** The value of the Showing filter that lists removed records. */
    public const REMOVED = 'removed';

    /**
     * What the Showing filter offers besides the active ones, here and for
     * products.
     *
     * @return array<string, string>
     */
    public static function showingOptions(): array
    {
        return ['1' => __('Archived'), 'all' => __('Active and archived'), self::REMOVED => __('Removed, to restore')];
    }

    /**
     * A customer or product query narrowed to what the Showing filter asks
     * for: active (null), archived ('1'), both ('all') or removed.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public static function showing(Builder $query, mixed $showing): Builder
    {
        return match ($showing) {
            self::REMOVED => $query->onlyTrashed(),
            '1' => $query->whereNotNull('archived_at'),
            'all' => $query,
            default => $query->whereNull('archived_at'),
        };
    }

    public function spec(Organization $organization, string $key): ListSpec
    {
        return new ListSpec(
            key: $key,
            sorts: [
                'name' => new Sort(__('Name'), 'name', 'asc'),
                'newest' => new Sort(__('Newest'), 'created_at'),
                'city' => new Sort(__('City'), 'city', 'asc'),
            ],
            defaultSort: 'name',
            filters: [
                new Search('q', __('Search'), ['name', 'name_ar', 'vat_number', 'email'], __('Name, VAT number or email')),
                new Search('city', __('City'), ['city']),
                new Choice('archived', __('Showing'), self::showingOptions(), fn (QueryBuilder $query, string $value) => null, __('Active')),
                new DateRange('created_at', __('Added'), CountryClock::timeZone($organization->country), instant: true),
            ],
        );
    }

    public function page(string $organizationId, ListState $list): LengthAwarePaginator
    {
        return $list->paginate($this->query($organizationId, $list));
    }

    public function csv(string $organizationId, ListState $list, string $fileName): StreamedResponse
    {
        return CsvExport::download($fileName, [
            __('Name') => fn (Customer $customer): string => (string) $customer->name,
            __('Name in Arabic') => fn (Customer $customer): string => (string) $customer->name_ar,
            __('VAT number') => fn (Customer $customer): string => (string) $customer->vat_number,
            __('City') => fn (Customer $customer): string => (string) $customer->city,
            __('Country') => fn (Customer $customer): string => (string) $customer->country_code,
            __('Email') => fn (Customer $customer): string => (string) $customer->email,
            __('Phone') => fn (Customer $customer): string => (string) $customer->phone,
            __('Archived') => fn (Customer $customer): string => $customer->archived_at === null ? '' : (string) $customer->archived_at,
        ], $list->apply($this->query($organizationId, $list))->lazy(500));
    }

    /**
     * @return Builder<Customer>
     */
    private function query(string $organizationId, ListState $list): Builder
    {
        return self::showing(Customer::query(), $list->value('archived'))->where('org_id', $organizationId);
    }
}
