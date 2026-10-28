<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Imports;

use App\Domains\Audit\Attribution\Channel;
use App\Domains\Audit\Attribution\Origin;
use App\Domains\Invoice\Http\Requests\CreateInvoiceRequest;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Invoice\Services\Catalogue;
use App\Domains\Invoice\Services\InvoiceDrafter;
use App\Domains\Invoice\Support\CatalogueRules;
use App\Domains\Organization\Models\Organization;
use Illuminate\Http\Request;
use Illuminate\Routing\Redirector;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Rows of an imported CSV, checked by exactly the rules the portal's forms
 * and the API check by - the customer and product rules CatalogueRules
 * keeps, an invoice's country's InvoiceRules through CreateInvoiceRequest -
 * and, when asked, saved: customers and products as the forms save them,
 * invoices as drafts through InvoiceDrafter. Nothing here issues.
 *
 * Every record it creates says it arrived by import (Origin::during).
 */
final class ImportRows
{
    /** Booleans as a spreadsheet writes them. */
    private const TRUE = ['1', 'true', 'yes', 'y'];

    private const FALSE = ['0', 'false', 'no', 'n'];

    /** The columns the page previews a record by. */
    private const PREVIEW = [
        ImportColumns::INVOICES => ['invoice_number', 'issue_date', 'buyer_name', 'currency'],
        ImportColumns::CUSTOMERS => ['name', 'vat_number', 'city', 'country_code'],
        ImportColumns::PRODUCTS => ['name', 'sku', 'unit_price', 'tax_rate'],
    ];

    private const PREVIEW_RECORDS = 10;

    /** @var list<string> The kind's yes-or-no columns, while a file is processed. */
    private array $booleans = [];

    public function __construct(
        private readonly ImportColumns $columns,
        private readonly CatalogueRules $rules,
        private readonly Catalogue $catalogue,
        private readonly InvoiceDrafter $drafter,
        private readonly Redirector $redirector,
    ) {}

    /**
     * The preview's columns, in the order the page shows them. Not read off
     * a stored record: MySQL keeps a JSON object's keys in an order of its
     * own.
     *
     * @return list<string>
     */
    public static function previewColumns(string $kind): array
    {
        return ['row', ...self::PREVIEW[$kind] ?? [], ...($kind === ImportColumns::INVOICES ? ['lines'] : [])];
    }

    /**
     * Check every row and, with $save, keep the records that pass.
     *
     * @param  list<array{row: int, values: array<string, string>}>  $rows
     * @return array{valid: int, invalid: int, saved: int, problems: list<array{row: int, field: string, message: string}>, preview: list<array<string, string>>}
     */
    public function process(string $kind, Organization $organization, ?string $branchId, array $rows, bool $save): array
    {
        $result = ['valid' => 0, 'invalid' => 0, 'saved' => 0, 'problems' => [], 'preview' => []];
        $known = $this->columns->columns($kind, $organization);
        $this->booleans = $this->columns->booleans($kind, $organization);

        foreach ($this->records($kind, $rows) as $record) {
            $keep = null;
            $problems = $this->unknownColumns($record, $known);

            if ($problems === []) {
                [$problems, $keep] = $kind === ImportColumns::INVOICES
                    ? $this->invoice($organization, $record)
                    : $this->catalogueRecord($kind, $organization, $record);
            }

            if ($problems === [] && $save) {
                $problems = $this->keep($kind, $organization, $branchId, $record, $keep);
            }

            $count = count($record['rows']);

            if ($problems !== []) {
                $result['invalid'] += $count;
                array_push($result['problems'], ...$problems);

                continue;
            }

            $result['valid'] += $count;
            $result['saved'] += $save ? 1 : 0;

            if (count($result['preview']) < self::PREVIEW_RECORDS) {
                $result['preview'][] = $this->preview($kind, $record);
            }
        }

        return $result;
    }

    /**
     * The rows as records: each row a customer or product, and the rows of
     * one invoice number together, an invoice.
     *
     * @param  list<array{row: int, values: array<string, string>}>  $rows
     * @return list<array{rows: list<array{row: int, values: array<string, string>}>}>
     */
    private function records(string $kind, array $rows): array
    {
        if ($kind !== ImportColumns::INVOICES) {
            return array_map(fn (array $row): array => ['rows' => [$row]], $rows);
        }

        $records = [];

        foreach ($rows as $row) {
            $number = $row['values']['invoice_number'] ?? null;
            $key = $number === null ? 'row:'.$row['row'] : 'number:'.$number;
            $records[$key]['rows'][] = $row;
        }

        return array_values($records);
    }

    /**
     * A column the kind does not have: refused rather than dropped, because
     * a misspelt column is a field someone thinks they filled.
     *
     * @param  array{rows: list<array{row: int, values: array<string, string>}>}  $record
     * @param  list<string>  $known
     * @return list<array{row: int, field: string, message: string}>
     */
    private function unknownColumns(array $record, array $known): array
    {
        $first = $record['rows'][0];
        $unknown = array_diff(array_keys($first['values']), $known);

        return array_values(array_map(fn (string $column): array => [
            'row' => $first['row'],
            'field' => $column,
            'message' => __('There is no column :column. Use the template\'s columns.', ['column' => $column]),
        ], $unknown));
    }

    /**
     * An invoice checked as the API checks one.
     *
     * @param  array{rows: list<array{row: int, values: array<string, string>}>}  $record
     * @return array{0: list<array{row: int, field: string, message: string}>, 1: CreateInvoiceRequest|null}
     */
    private function invoice(Organization $organization, array $record): array
    {
        $first = $record['rows'][0];
        $payload = ['lines' => []];

        foreach ($first['values'] as $column => $value) {
            if (! str_starts_with($column, ImportColumns::LINE)) {
                data_set($payload, $column, $this->value($column, $value));
            }
        }

        foreach ($record['rows'] as $index => $row) {
            $line = [];

            foreach ($row['values'] as $column => $value) {
                if (str_starts_with($column, ImportColumns::LINE)) {
                    $line[substr($column, strlen(ImportColumns::LINE))] = $this->value($column, $value);
                }
            }

            $payload['lines'][$index] = $line;
        }

        $number = $payload['invoice_number'] ?? null;

        if (is_string($number) && Invoice::acrossTenants()->where('org_id', $organization->id)->where('invoice_number', $number)->exists()) {
            return [[['row' => $first['row'], 'field' => 'invoice_number', 'message' => __('An invoice numbered :number already exists.', ['number' => $number])]], null];
        }

        $request = CreateInvoiceRequest::create('/', 'POST', $payload);
        $request->setContainer(app())->setRedirector($this->redirector);

        try {
            $request->validateResolved();
        } catch (ValidationException $e) {
            return [$this->invoiceProblems($record, $e->errors()), null];
        }

        return [[], $request];
    }

    /**
     * Each error at the row it is about: a line's at the line's row, the
     * invoice's at its first.
     *
     * @param  array{rows: list<array{row: int, values: array<string, string>}>}  $record
     * @param  array<string, list<string>>  $errors
     * @return list<array{row: int, field: string, message: string}>
     */
    private function invoiceProblems(array $record, array $errors): array
    {
        $problems = [];

        foreach ($errors as $field => $messages) {
            $row = $record['rows'][0]['row'];

            if (preg_match('/^lines\.(\d+)\.(.+)$/', $field, $match) === 1) {
                $row = $record['rows'][(int) $match[1]]['row'] ?? $row;
                $field = ImportColumns::LINE.$match[2];
            }

            foreach ($messages as $message) {
                $problems[] = ['row' => $row, 'field' => $field, 'message' => $message];
            }
        }

        return $problems;
    }

    /**
     * A customer or product checked as its form checks one.
     *
     * @param  array{rows: list<array{row: int, values: array<string, string>}>}  $record
     * @return array{0: list<array{row: int, field: string, message: string}>, 1: array<string, mixed>|null}
     */
    private function catalogueRecord(string $kind, Organization $organization, array $record): array
    {
        $row = $record['rows'][0];
        $values = [];

        foreach ($row['values'] as $column => $value) {
            $values[$column] = $this->value($column, $value);
        }

        $request = Request::create('/', 'POST', $values);

        [$rules, $messages] = $kind === ImportColumns::CUSTOMERS
            ? $this->rules->customer($organization, $request)
            : $this->rules->product($organization, $request);

        $validator = Validator::make($values, $rules, $messages);

        if ($validator->fails()) {
            $problems = [];

            foreach ($validator->errors()->messages() as $field => $fieldMessages) {
                foreach ($fieldMessages as $message) {
                    $problems[] = ['row' => $row['row'], 'field' => $field, 'message' => $message];
                }
            }

            return [$problems, null];
        }

        $validated = $validator->validated();

        return [[], $kind === ImportColumns::PRODUCTS ? $this->rules->keptProduct($organization, $validated) : $validated];
    }

    /**
     * Save one record. A record that still cannot be saved - another row
     * took its number or VAT number first - is that record's problem.
     *
     * @param  array{rows: list<array{row: int, values: array<string, string>}>}  $record
     * @return list<array{row: int, field: string, message: string}>
     */
    private function keep(string $kind, Organization $organization, ?string $branchId, array $record, mixed $checked): array
    {
        try {
            Origin::during(Channel::Import, function () use ($kind, $organization, $branchId, $checked): void {
                match ($kind) {
                    ImportColumns::INVOICES => $this->drafter->draft($checked->draft(), (string) $organization->id, $branchId, countryFields: $checked->countryFields()),
                    ImportColumns::CUSTOMERS => $this->catalogue->saveCustomer((string) $organization->id, $checked),
                    default => $this->catalogue->saveProduct((string) $organization->id, $checked),
                };
            });
        } catch (Throwable $e) {
            Log::warning('CSV import: a record could not be saved', ['org_id' => $organization->id, 'kind' => $kind, 'row' => $record['rows'][0]['row'], 'error' => $e->getMessage()]);

            return [['row' => $record['rows'][0]['row'], 'field' => '', 'message' => $e instanceof ValidationException
                ? implode(' ', $e->validator->errors()->all())
                : __('It could not be saved. Nothing of it was written.')]];
        }

        return [];
    }

    /**
     * @param  array{rows: list<array{row: int, values: array<string, string>}>}  $record
     * @return array<string, string>
     */
    private function preview(string $kind, array $record): array
    {
        $first = $record['rows'][0];
        $shown = ['row' => (string) $first['row']];

        foreach (self::PREVIEW[$kind] as $column) {
            $shown[$column] = (string) ($first['values'][$column] ?? '');
        }

        if ($kind === ImportColumns::INVOICES) {
            $shown['lines'] = (string) count($record['rows']);
        }

        return $shown;
    }

    /**
     * A cell as validation reads it: in a yes-or-no column, yes and no as
     * 1 and 0. Every other cell as it was written.
     */
    private function value(string $column, string $value): string
    {
        if (! in_array($column, $this->booleans, true)) {
            return $value;
        }

        $lower = mb_strtolower($value);

        return match (true) {
            in_array($lower, self::TRUE, true) => '1',
            in_array($lower, self::FALSE, true) => '0',
            default => $value,
        };
    }
}
