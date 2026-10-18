<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Services;

use App\Domains\Invoice\Models\Customer;
use App\Domains\Invoice\Models\Product;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

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

    /**
     * Where to send a buyer's invoice: the email of the customer with their
     * VAT number, when there is one.
     */
    public function emailFor(string $organizationId, ?string $vatNumber): ?string
    {
        if ($vatNumber === null || $vatNumber === '') {
            return null;
        }

        return Customer::withoutTenantScope(fn () => Customer::query()
            ->where('org_id', $organizationId)
            ->where('vat_number', $vatNumber)
            ->value('email'));
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
     * The Arabic name of the product an invoice line names, by its id or
     * else its code; null when it names none, none is found, or the product
     * has no Arabic name. Only what the organization wrote down: nothing is
     * translated.
     */
    public function productArabicName(string $organizationId, ?string $productId, ?string $code): ?string
    {
        $product = match (true) {
            $productId !== null && Str::isUuid($productId) => $this->product($organizationId, $productId),
            filled($code) => Product::withoutTenantScope(fn () => Product::query()->where('org_id', $organizationId)->where('sku', $code)->first()),
            default => null,
        };

        return filled($product?->name_ar) ? (string) $product->name_ar : null;
    }

    /** The Arabic name of a saved customer; null when there is none. */
    public function customerArabicName(string $organizationId, string $customerId): ?string
    {
        $customer = Str::isUuid($customerId) ? $this->customer($organizationId, $customerId) : null;

        return filled($customer?->name_ar) ? (string) $customer->name_ar : null;
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
     * updates them rather than making a second record. From a UAE invoice,
     * the buyer's Peppol endpoint and emirate are kept too.
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
        // Its other identifier (BT-46) when it has one, and the terms it was
        // invoiced on, for its next invoice.
        $other = array_filter([
            'buyer_id' => ($invoice['buyer_id'] ?? null) ?: null,
            'buyer_id_scheme' => ($invoice['buyer_id_scheme'] ?? null) ?: null,
            'payment_terms' => ($invoice['payment_terms'] ?? null) ?: null,
        ]);

        return $this->saveCustomer($organizationId, [
            'name' => $name,
            'name_ar' => ($invoice['buyer_name_ar'] ?? null) ?: null,
            'vat_number' => $vat,
            ...array_intersect_key($address, array_flip(Customer::ADDRESS)),
            ...$other,
            ...$this->uaeBuyer($invoice),
            'archived_at' => null,
        ], $this->sameBuyer($organizationId, $vat, $other));
    }

    /**
     * The customer already kept for this buyer: by VAT number, or, for a
     * buyer with none, by the identifier and register it is known by.
     *
     * @param  array<string, string>  $other
     */
    private function sameBuyer(string $organizationId, ?string $vat, array $other): ?Customer
    {
        if ($vat === null && ! isset($other['buyer_id'], $other['buyer_id_scheme'])) {
            return null;
        }

        return Customer::withoutTenantScope(fn () => Customer::query()
            ->where('org_id', $organizationId)
            ->when($vat !== null,
                fn ($query) => $query->where('vat_number', $vat),
                fn ($query) => $query->whereNull('vat_number')->where('buyer_id', $other['buyer_id'])->where('buyer_id_scheme', $other['buyer_id_scheme']))
            ->first());
    }

    /**
     * A UAE invoice's buyer endpoint and emirate, as a customer keeps them;
     * a Saudi invoice has neither, so nothing is added. An endpoint in
     * another Peppol scheme (a buyer abroad) is not the shape a customer
     * keeps, and is left as it was.
     *
     * @param  array<string, mixed>  $invoice
     * @return array<string, string>
     */
    private function uaeBuyer(array $invoice): array
    {
        $endpoint = (string) ($invoice['buyer_endpoint'] ?? '');
        $subdivision = (string) ($invoice['buyer_subdivision'] ?? '');

        return array_filter([
            'peppol_endpoint' => preg_match(Customer::UAE_ENDPOINT, $endpoint) === 1 ? $endpoint : '',
            'subdivision' => $subdivision,
        ], fn (string $value): bool => $value !== '');
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
    }
}
