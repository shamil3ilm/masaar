<?php

declare(strict_types=1);

namespace App\Domains\Books\Services;

use App\Domains\Books\Models\Supplier;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

/**
 * An organization's own suppliers (Books): listed, added, changed and
 * removed. Every query names the organization as well as relying on the
 * tenant scope, so a foreign id is unfindable rather than merely unscoped.
 *
 * A VAT number is one supplier's: a second supplier with the same number is
 * refused, while a removed one's number may be used again.
 */
class Suppliers
{
    /** @return Collection<int, Supplier> */
    public function all(string $organizationId): Collection
    {
        return Supplier::query()->where('org_id', $organizationId)->orderBy('name')->get();
    }

    public function find(string $organizationId, string $id): ?Supplier
    {
        return Supplier::query()->where('org_id', $organizationId)->find($id);
    }

    /**
     * The supplier with this VAT number, or with this name when there is no
     * number, for an import that names suppliers rather than choosing them.
     */
    public function match(string $organizationId, ?string $vatNumber, string $name): ?Supplier
    {
        $vatNumber = trim((string) $vatNumber);

        return Supplier::query()->where('org_id', $organizationId)
            ->when($vatNumber !== '',
                fn ($query) => $query->where('vat_number', $vatNumber),
                fn ($query) => $query->whereRaw('LOWER(name) = ?', [mb_strtolower(trim($name))]))
            ->orderBy('created_at')
            ->first();
    }

    /**
     * @param  array<string, mixed>  $values
     *
     * @throws ValidationException
     */
    public function save(string $organizationId, array $values, ?Supplier $supplier = null): Supplier
    {
        $vatNumber = trim((string) ($values['vat_number'] ?? ''));

        if ($vatNumber !== '') {
            $taken = Supplier::query()->where('org_id', $organizationId)->where('vat_number', $vatNumber)
                ->when($supplier !== null, fn ($query) => $query->whereKeyNot($supplier->id))
                ->exists();

            if ($taken) {
                throw ValidationException::withMessages(['vat_number' => __('Another supplier already has this VAT number.')]);
            }
        }

        $supplier ??= new Supplier(['org_id' => $organizationId]);
        $supplier->fill([
            'name' => trim((string) $values['name']),
            'name_ar' => $this->optional($values['name_ar'] ?? null),
            'vat_number' => $vatNumber === '' ? null : $vatNumber,
            'country_code' => $this->optional(isset($values['country_code']) ? strtoupper((string) $values['country_code']) : null),
            'city' => $this->optional($values['city'] ?? null),
            'subdivision' => $this->optional($values['subdivision'] ?? null),
            'email' => $this->optional($values['email'] ?? null),
            'phone' => $this->optional($values['phone'] ?? null),
        ]);
        $supplier->save();

        return $supplier;
    }

    /** Removed, not deleted: the purchases that name it keep it. */
    public function remove(Supplier $supplier): void
    {
        $supplier->delete();
    }

    private function optional(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
