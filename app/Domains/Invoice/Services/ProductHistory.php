<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Services;

use App\Domains\Invoice\Models\Product;
use App\Http\Listing\Filters\Choice;
use App\Http\Listing\Filters\Search;
use App\Http\Listing\ListSpec;
use App\Http\Listing\ListState;
use App\Http\Listing\Sort;
use Illuminate\Contracts\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * An organization's products as a list, as CustomerHistory is its
 * customers: active unless the list asks for the archived ones, or the
 * removed ones to bring back. `archived=1` still means what it did. Every
 * query names the organization, as Catalogue's do.
 */
class ProductHistory
{
    public function spec(string $key): ListSpec
    {
        return new ListSpec(
            key: $key,
            sorts: [
                'name' => new Sort(__('Name'), 'name', 'asc'),
                'newest' => new Sort(__('Newest'), 'created_at'),
                'price' => new Sort(__('Price'), 'unit_price'),
            ],
            defaultSort: 'name',
            filters: [
                new Search('q', __('Search'), ['name', 'name_ar', 'sku'], __('Name or code')),
                new Choice('archived', __('Showing'), CustomerHistory::showingOptions(), fn (QueryBuilder $query, string $value) => null, __('Active')),
            ],
        );
    }

    public function page(string $organizationId, ListState $list): LengthAwarePaginator
    {
        return $list->paginate($this->query($organizationId, $list));
    }

    /**
     * @return Builder<Product>
     */
    private function query(string $organizationId, ListState $list): Builder
    {
        return CustomerHistory::showing(Product::query(), $list->value('archived'))->where('org_id', $organizationId);
    }
}
