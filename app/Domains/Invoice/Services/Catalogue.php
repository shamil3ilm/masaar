<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Services;

use App\Domains\Invoice\Models\Customer;
use App\Domains\Invoice\Models\Product;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

/**
 * An organization's customers and products.
 *
 * Every query names the organization rather than leaning on the tenant scope
 * alone: the scope stands down in console context, and a foreign id should be
 * unfindable, not merely unscoped.
 */
class Catalogue
{
    /**
     * @return LengthAwarePaginator<int, Customer>
     */
    public function customers(string $organizationId, ?string $search, bool $archived, int $perPage): LengthAwarePaginator
    {
        return Customer::withoutTenantScope(fn () => Customer::query()
            ->where('org_id', $organizationId)
            ->when($archived, fn ($query) => $query->whereNotNull('archived_at'), fn ($query) => $query->whereNull('archived_at'))
            ->when($search !== null, fn ($query) => $query->where(fn ($query) => $query
                ->whereRaw("name LIKE ? ESCAPE '!'", ['%'.$this->escapeLike((string) $search).'%'])
                ->orWhereRaw("name_ar LIKE ? ESCAPE '!'", ['%'.$this->escapeLike((string) $search).'%'])
                ->orWhere('vat_number', $search)))
            ->orderBy('name')
            ->paginate($perPage));
    }

    /**
     * @return LengthAwarePaginator<int, Product>
     */
    public function products(string $organizationId, ?string $search, bool $archived, int $perPage): LengthAwarePaginator
    {
        return Product::withoutTenantScope(fn () => Product::query()
            ->where('org_id', $organizationId)
            ->when($archived, fn ($query) => $query->whereNotNull('archived_at'), fn ($query) => $query->whereNull('archived_at'))
            ->when($search !== null, fn ($query) => $query->where(fn ($query) => $query
                ->whereRaw("name LIKE ? ESCAPE '!'", ['%'.$this->escapeLike((string) $search).'%'])
                ->orWhereRaw("name_ar LIKE ? ESCAPE '!'", ['%'.$this->escapeLike((string) $search).'%'])
                ->orWhere('sku', $search)))
            ->orderBy('name')
            ->paginate($perPage));
    }

    /**
     * The active customers, for the invoice form's picker.
     *
     * @return Collection<int, Customer>
     */
    public function customerChoices(string $organizationId): Collection
    {
        return Customer::withoutTenantScope(fn () => Customer::query()
            ->where('org_id', $organizationId)
            ->whereNull('archived_at')
            ->orderBy('name')
            ->get());
    }

    /**
     * @return Collection<int, Product>
     */
    public function productChoices(string $organizationId): Collection
    {
        return Product::withoutTenantScope(fn () => Product::query()
            ->where('org_id', $organizationId)
            ->whereNull('archived_at')
            ->orderBy('name')
            ->get());
    }

    public function customer(string $organizationId, string $customerId): ?Customer
    {
        return Customer::withoutTenantScope(fn () => Customer::query()->where('org_id', $organizationId)->find($customerId));
    }

    public function product(string $organizationId, string $productId): ?Product
    {
        return Product::withoutTenantScope(fn () => Product::query()->where('org_id', $organizationId)->find($productId));
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    public function saveCustomer(string $organizationId, array $fields, ?Customer $customer = null): Customer
    {
        $customer ??= new Customer(['org_id' => $organizationId]);
        $customer->forceFill(['org_id' => $organizationId])->fill($fields)->save();

        return $customer;
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    public function saveProduct(string $organizationId, array $fields, ?Product $product = null): Product
    {
        $product ??= new Product(['org_id' => $organizationId]);
        $product->forceFill(['org_id' => $organizationId])->fill($fields)->save();

        return $product;
    }

    public function archive(Customer|Product $record, bool $archived): void
    {
        $record->forceFill(['archived_at' => $archived ? now() : null])->save();
    }

    /**
     * Keep the buyer an invoice was just written for, when asked to.
     *
     * Matched by VAT number when there is one, so saving the same buyer twice
     * updates them rather than making a second record.
     *
     * @param  array<string, mixed>  $invoice  The validated invoice fields
     */
    public function rememberBuyer(string $organizationId, array $invoice): ?Customer
    {
        $name = trim((string) ($invoice['buyer_name'] ?? ''));

        if ($name === '') {
            return null;
        }

        $vat = ($invoice['buyer_vat_number'] ?? null) ?: null;
        $address = is_array($invoice['buyer_address'] ?? null) ? $invoice['buyer_address'] : [];

        $existing = $vat === null ? null : Customer::withoutTenantScope(fn () => Customer::query()
            ->where('org_id', $organizationId)
            ->where('vat_number', $vat)
            ->first());

        return $this->saveCustomer($organizationId, [
            'name' => $name,
            'name_ar' => ($invoice['buyer_name_ar'] ?? null) ?: null,
            'vat_number' => $vat,
            ...array_intersect_key($address, array_flip(Customer::ADDRESS)),
            'archived_at' => null,
        ], $existing);
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
    }
}
