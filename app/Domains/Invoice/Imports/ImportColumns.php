<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Imports;

use App\Domains\Compliance\Engine\Services\JurisdictionResolver;
use App\Domains\Invoice\Support\CatalogueRules;
use App\Domains\Invoice\Support\UnitCode;
use App\Domains\Organization\Models\Organization;
use App\Support\CountryClock;
use Illuminate\Http\Request;

/**
 * The columns a CSV of invoices, customers or products has for an
 * organization, read from the rules its country checks them by - so the
 * template and the import follow a country's fields without naming one.
 *
 * An invoice row holds the invoice and one of its lines: the invoice's
 * fields as the API names them (buyer_address.city), a line's with "line."
 * before them (line.unit_price). Rows with the same invoice_number are one
 * invoice, a line each; a row without a number is an invoice of one line.
 * Nested lists - charges, prepayments - are left to the form and the API.
 */
final class ImportColumns
{
    public const INVOICES = 'invoices';

    public const CUSTOMERS = 'customers';

    public const PRODUCTS = 'products';

    public const KINDS = [self::INVOICES, self::CUSTOMERS, self::PRODUCTS];

    /** Before a line's own field, in an invoice row. */
    public const LINE = 'line.';

    /** An invoice's lists that do not fit one row a line. */
    private const LISTS = ['lines', 'charges', 'prepayments'];

    public function __construct(
        private readonly JurisdictionResolver $jurisdictions,
        private readonly CatalogueRules $catalogue,
    ) {}

    public static function label(string $kind): string
    {
        return match ($kind) {
            self::INVOICES => __('Invoices'),
            self::CUSTOMERS => __('Customers'),
            default => __('Products'),
        };
    }

    /**
     * The columns, in the order the rules list them.
     *
     * @return list<string>
     */
    public function columns(string $kind, Organization $organization): array
    {
        return array_keys($this->rules($kind, $organization));
    }

    /**
     * The columns every row has to fill.
     *
     * @return list<string>
     */
    public function required(string $kind, Organization $organization): array
    {
        return array_keys(array_filter(
            $this->rules($kind, $organization),
            fn (array $rule): bool => in_array('required', $rule, true),
        ));
    }

    /**
     * The columns that are yes or no, which a spreadsheet may write as
     * yes, no, true or false.
     *
     * @return list<string>
     */
    public function booleans(string $kind, Organization $organization): array
    {
        return array_keys(array_filter(
            $this->rules($kind, $organization),
            fn (array $rule): bool => in_array('boolean', $rule, true),
        ));
    }

    /**
     * The template: the columns, and one example row the country's rules
     * accept, with the byte-order mark spreadsheets read UTF-8 by.
     */
    public function template(string $kind, Organization $organization): string
    {
        $columns = $this->columns($kind, $organization);
        $example = $this->example($kind, $organization);

        $stream = fopen('php://temp', 'w+');
        fputcsv($stream, $columns, escape: '');
        fputcsv($stream, array_map(fn (string $column): string => (string) ($example[$column] ?? ''), $columns), escape: '');
        rewind($stream);

        return "\xEF\xBB\xBF".stream_get_contents($stream);
    }

    /**
     * The import's columns and the rule each one has, flattened from the
     * country's rules.
     *
     * @return array<string, list<mixed>>
     */
    private function rules(string $kind, Organization $organization): array
    {
        $request = Request::create('/', 'POST');

        $rules = match ($kind) {
            self::INVOICES => $this->jurisdictions->forRules($organization)->invoiceRules()->rules($request),
            self::CUSTOMERS => $this->catalogue->customer($organization, $request)[0],
            default => $this->catalogue->product($organization, $request)[0],
        };

        $columns = [];

        foreach ($rules as $key => $rule) {
            $rule = array_values(is_array($rule) ? $rule : explode('|', (string) $rule));
            $column = $this->column((string) $key, $rule);

            if ($column !== null) {
                $columns[$column] = $rule;
            }
        }

        return $columns;
    }

    /**
     * The column a rule's key is, or null when it does not fit a row: a
     * list, a group's own key (its fields are columns), or an id only a
     * system knows.
     *
     * @param  list<mixed>  $rule
     */
    private function column(string $key, array $rule): ?string
    {
        if (in_array($key, self::LISTS, true) || in_array('uuid', $rule, true)) {
            return null;
        }

        if (str_starts_with($key, 'lines.*.')) {
            $field = substr($key, strlen('lines.*.'));

            return str_contains($field, '.') || in_array('array', $rule, true) ? null : self::LINE.$field;
        }

        if (str_contains($key, '*') || in_array('array', $rule, true)) {
            return null;
        }

        return $key;
    }

    /**
     * One row of example values, from the invoice the country offers its
     * developers (Website::apiExample): the invoice and its first line, or
     * its buyer as a customer, or its line as a product.
     *
     * @return array<string, string>
     */
    private function example(string $kind, Organization $organization): array
    {
        $jurisdiction = $this->jurisdictions->forRules($organization);
        $invoice = $jurisdiction->website()->apiExample(CountryClock::now($organization->country)->toDateString());
        $line = (array) ($invoice['lines'][0] ?? []);

        $flat = [];

        foreach ($invoice as $key => $value) {
            if (is_array($value) && ! array_is_list($value)) {
                foreach ($value as $field => $inner) {
                    $flat[$key.'.'.$field] = (string) $inner;
                }
            } elseif (! is_array($value)) {
                $flat[(string) $key] = (string) $value;
            }
        }

        return match ($kind) {
            self::INVOICES => [
                ...$flat,
                ...array_combine(array_map(fn (string $field): string => self::LINE.$field, array_keys($line)), array_map('strval', $line)),
            ],
            self::CUSTOMERS => [
                'name' => $flat['buyer_name'] ?? '',
                'name_ar' => $flat['buyer_name_ar'] ?? '',
                'vat_number' => $flat['buyer_vat_number'] ?? '',
                ...array_filter([
                    'street' => $flat['buyer_address.street'] ?? null,
                    'building_number' => $flat['buyer_address.building_number'] ?? null,
                    'district' => $flat['buyer_address.district'] ?? null,
                    'city' => $flat['buyer_address.city'] ?? null,
                    'postal_code' => $flat['buyer_address.postal_code'] ?? null,
                    'country_code' => $flat['buyer_address.country_code'] ?? null,
                ]),
            ],
            default => [
                'name' => (string) ($line['description'] ?? $line['item_name'] ?? ''),
                'name_ar' => (string) ($line['description_ar'] ?? ''),
                'unit_code' => UnitCode::DEFAULT,
                'unit_price' => (string) ($line['unit_price'] ?? ''),
                'tax_rate' => $jurisdiction->invoiceRules()->standardRate(),
            ],
        };
    }
}
