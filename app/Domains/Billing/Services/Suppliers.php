<?php

declare(strict_types=1);

namespace App\Domains\Billing\Services;

use App\Domains\Audit\Services\AuditService;
use App\Domains\Billing\Models\Supplier;
use App\Domains\Billing\Settings\FinanceSettings;
use App\Domains\Billing\Settings\Iban;
use App\Http\Listing\CsvExport;
use App\Http\Listing\Filters\Choice;
use App\Http\Listing\Filters\Search;
use App\Http\Listing\ListSpec;
use App\Http\Listing\ListState;
use App\Http\Listing\Sort;
use App\Support\CountryNames;
use DomainException;
use Illuminate\Contracts\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The platform's suppliers (Admin › Finance › Suppliers): added, changed,
 * archived, and removed while nothing names them.
 *
 * What a form cannot check alone is checked here: the IBAN (ISO 13616), the
 * SWIFT code, a country and a currency the platform knows. Every change is
 * in the audit log with the values before and after.
 */
class Suppliers
{
    public function __construct(
        private readonly FinanceSettings $settings,
        private readonly Expenses $expenses,
        private readonly AuditService $audit,
    ) {}

    /** @return array<string, string> */
    public static function showingOptions(): array
    {
        return ['archived' => __('Archived'), 'removed' => __('Removed'), 'all' => __('All but removed')];
    }

    public function spec(): ListSpec
    {
        return new ListSpec(
            key: 'admin.finance.suppliers',
            sorts: [
                'name' => new Sort(__('Name'), 'name', 'asc'),
                'newest' => new Sort(__('Newest'), 'created_at'),
            ],
            defaultSort: 'name',
            filters: [
                new Search('q', __('Search'), ['name', 'vat_number', 'iban'], __('Name, VAT number or IBAN')),
                new Choice('category', __('Category'), $this->settings->expenseCategories(), 'category'),
                new Choice('showing', __('Showing'), self::showingOptions(), fn (QueryBuilder $query, string $value) => null, __('Active')),
            ],
        );
    }

    public function page(ListState $list): LengthAwarePaginator
    {
        return $list->paginate($this->query($list));
    }

    public function csv(ListState $list, string $fileName): StreamedResponse
    {
        $categories = $this->settings->expenseCategories();

        return CsvExport::download($fileName, [
            __('Name') => fn (Supplier $supplier): string => $supplier->name,
            __('Country') => fn (Supplier $supplier): string => $supplier->country,
            __('VAT number') => fn (Supplier $supplier): string => (string) $supplier->vat_number,
            __('Currency') => fn (Supplier $supplier): string => $supplier->currency,
            __('Payment terms (days)') => fn (Supplier $supplier): string => (string) $supplier->payment_terms_days,
            __('Category') => fn (Supplier $supplier): string => $categories[(string) $supplier->category] ?? (string) $supplier->category,
            __('Bank') => fn (Supplier $supplier): string => (string) $supplier->bank_name,
            __('IBAN') => fn (Supplier $supplier): string => (string) $supplier->iban,
            __('SWIFT') => fn (Supplier $supplier): string => (string) $supplier->swift,
            __('Archived') => fn (Supplier $supplier): string => $supplier->archived_at?->toDateString() ?? '',
        ], $list->apply($this->query($list))->lazy(500));
    }

    /** A supplier by id, removed ones too. */
    public function find(string $id): ?Supplier
    {
        return Supplier::withTrashed()->find($id);
    }

    /**
     * The suppliers an expense can name: neither archived nor removed, by
     * name, plus the one it already names.
     *
     * @return Collection<int, Supplier>
     */
    public function choosable(?string $alsoId = null): Collection
    {
        return Supplier::query()
            ->where(fn (Builder $query) => $query->whereNull('archived_at')->when($alsoId !== null, fn (Builder $query) => $query->orWhere('id', $alsoId)))
            ->orderBy('name')
            ->get();
    }

    /**
     * Every supplier by name, archived and removed ones too: what a list of
     * expenses filters by.
     *
     * @return array<string, string> id => name
     */
    public function names(): array
    {
        return Supplier::withTrashed()->orderBy('name')->pluck('name', 'id')->map(fn ($name): string => (string) $name)->all();
    }

    /**
     * Add a supplier, or change one.
     *
     * @param  array<string, mixed>  $data  as the form sends it
     *
     * @throws ValidationException
     */
    public function save(?Supplier $supplier, array $data, ?string $actorId): Supplier
    {
        $values = $this->checked($data);
        $supplier ??= new Supplier;
        $before = $supplier->exists ? $supplier->only(array_keys($values)) : null;

        $supplier->fill($values)->save();

        $this->audit->logChange($before === null ? 'finance.supplier.created' : 'finance.supplier.updated', 'Supplier', (string) $supplier->id,
            $before, $supplier->only(array_keys($values)), [], $actorId);

        return $supplier;
    }

    /** Out of the pickers, still on the expenses that name it. */
    public function archive(Supplier $supplier, ?string $actorId): void
    {
        $supplier->forceFill(['archived_at' => now(), 'archived_by' => $actorId])->save();
        $this->audit->logChange('finance.supplier.archived', 'Supplier', (string) $supplier->id, null, null, [], $actorId);
    }

    public function unarchive(Supplier $supplier, ?string $actorId): void
    {
        $supplier->forceFill(['archived_at' => null, 'archived_by' => null])->save();
        $this->audit->logChange('finance.supplier.unarchived', 'Supplier', (string) $supplier->id, null, null, [], $actorId);
    }

    /**
     * Removed, and restorable. One an expense names is archived instead:
     * its expenses would otherwise name nobody.
     *
     * @throws DomainException
     */
    public function remove(Supplier $supplier, ?string $actorId): void
    {
        if ($this->inUse($supplier)) {
            throw new DomainException(__(':name is on expenses, so it can only be archived.', ['name' => $supplier->name]));
        }

        $supplier->delete();
        $this->audit->logChange('finance.supplier.removed', 'Supplier', (string) $supplier->id, null, null, [], $actorId);
    }

    public function restore(Supplier $supplier, ?string $actorId): void
    {
        $supplier->restore();
        $this->audit->logChange('finance.supplier.restored', 'Supplier', (string) $supplier->id, null, null, [], $actorId);
    }

    /** Whether an expense names it, a void one too. */
    public function inUse(Supplier $supplier): bool
    {
        return $this->expenses->names($supplier);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private function checked(array $data): array
    {
        $country = strtoupper(trim((string) ($data['country'] ?? '')));
        $currency = strtoupper(trim((string) ($data['currency'] ?? '')));
        $iban = Iban::normalise((string) ($data['iban'] ?? ''));
        $swift = strtoupper(trim((string) ($data['swift'] ?? '')));
        $category = (string) ($data['category'] ?? '');

        if (! array_key_exists($country, CountryNames::options('en'))) {
            throw ValidationException::withMessages(['country' => __('Choose the supplier\'s country.')]);
        }

        if (! in_array($currency, FinanceSettings::expenseCurrencies(), true)) {
            throw ValidationException::withMessages(['currency' => __('Expenses are not kept in that currency.')]);
        }

        if ($iban !== '' && ! Iban::valid($iban)) {
            throw ValidationException::withMessages(['iban' => __('That is not a valid IBAN. Check its length and digits.')]);
        }

        if ($swift !== '' && preg_match('/^[A-Z]{6}[A-Z0-9]{2}([A-Z0-9]{3})?$/', $swift) !== 1) {
            throw ValidationException::withMessages(['swift' => __('A SWIFT code (BIC) is 8 or 11 letters and digits.')]);
        }

        if ($category !== '' && ! array_key_exists($category, $this->settings->expenseCategories())) {
            throw ValidationException::withMessages(['category' => __('Choose a category from the list.')]);
        }

        return [
            'name' => trim((string) $data['name']),
            'country' => $country,
            'vat_number' => $this->optional($data['vat_number'] ?? null),
            'address' => $this->optional($data['address'] ?? null),
            'currency' => $currency,
            'payment_terms_days' => (int) ($data['payment_terms_days'] ?? 30),
            'bank_name' => $this->optional($data['bank_name'] ?? null),
            'iban' => $iban === '' ? null : $iban,
            'swift' => $swift === '' ? null : $swift,
            'category' => $category === '' ? null : $category,
            'notes' => $this->optional($data['notes'] ?? null),
        ];
    }

    private function optional(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * @return Builder<Supplier>
     */
    private function query(ListState $list): Builder
    {
        return match ($list->value('showing')) {
            'archived' => Supplier::query()->whereNotNull('archived_at'),
            'removed' => Supplier::onlyTrashed(),
            'all' => Supplier::query(),
            default => Supplier::query()->whereNull('archived_at'),
        };
    }
}
