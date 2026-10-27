<?php

declare(strict_types=1);

namespace App\Domains\Books\Services;

use App\Domains\Audit\Attribution\Channel;
use App\Domains\Books\DTOs\PurchaseEntry;
use App\Domains\Books\DTOs\PurchaseLineEntry;
use App\Domains\Books\Enums\PurchaseTreatment;
use App\Domains\Books\Models\Supplier;
use App\Domains\Invoice\Imports\CsvReader;
use App\Domains\Organization\Models\Organization;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * Purchase invoices from a spreadsheet: one row per line, the rows of one
 * supplier's document - same supplier, number and type - making one
 * purchase. A supplier is found by its VAT number, or by its name where the
 * row gives none, and added when it is not on the list yet.
 *
 * All or nothing: every document is checked the way the form checks one
 * (Purchases::figures), and if any row is wrong nothing is recorded and
 * each problem is named by the spreadsheet's row number.
 */
class PurchaseImport
{
    public const COLUMNS = [
        'supplier_name', 'supplier_vat_number', 'supplier_country', 'invoice_number', 'invoice_date', 'received_on', 'document_type',
        'currency', 'exchange_rate', 'description', 'treatment', 'net', 'vat', 'vat_rate', 'capital_asset', 'deductible_share', 'due_on', 'paid_later', 'goods_type',
    ];

    private const REQUIRED = ['supplier_name', 'invoice_number', 'invoice_date', 'description', 'treatment', 'net'];

    private const MAX_ROWS = 2000;

    public function __construct(
        private readonly CsvReader $reader,
        private readonly Purchases $purchases,
        private readonly Suppliers $suppliers,
        private readonly BooksCountry $country,
    ) {}

    /**
     * A template with the header and one line of each kind, in the
     * organization's own country, currency and standard rate, for the
     * download. Written by a CSV writer, so a cell with a comma in it
     * ("Servers, October") is quoted and reads back as one cell.
     */
    public function template(Organization $organization): string
    {
        $country = strtoupper($this->country->of($organization)->code());
        $currency = $this->country->currency($organization);
        $rate = $this->country->standardRate($organization);
        $vat = fn (float $net): string => number_format($net * (float) $rate / 100, 2, '.', '');
        $local = ['Local Office Supplies', '', $country, 'LO-1001', '2026-10-05', '2026-10-07', 'invoice', $currency, ''];
        $rows = [
            self::COLUMNS,
            [...$local, 'Printer paper', 'standard', '1000.00', $vat(1000), (string) (float) $rate, 'no', '100', '2026-11-05', 'no'],
            [...$local, 'Laptop', 'standard', '4000.00', $vat(4000), (string) (float) $rate, 'yes', '100', '2026-11-05', 'no'],
            ['Cloud Hosting Ltd', '', 'IE', 'CH-77', '2026-10-01', '', 'invoice', 'USD', '3.75', 'Servers, October', 'reverse_charge', '200.00', '', (string) (float) $rate, 'no', '100', '2026-11-05', 'no'],
        ];

        $out = fopen('php://temp', 'w+');
        fwrite($out, "\xEF\xBB\xBF");

        foreach ($rows as $row) {
            fputcsv($out, $row, ',', '"', '', "\r\n");
        }

        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);

        return $csv;
    }

    /**
     * @return array{recorded: int, problems: list<string>}
     *
     * @throws DomainException when the file is not a CSV that can be read
     */
    public function import(Organization $organization, string $path): array
    {
        $read = $this->reader->read($path, self::MAX_ROWS);
        $missing = array_diff(self::REQUIRED, $read['header']);

        if ($missing !== []) {
            throw new DomainException(__('The file has no :columns column. Download the template to see the columns.', ['columns' => implode(', ', $missing)]));
        }

        $groups = [];
        $problems = [];

        foreach ($read['rows'] as $row) {
            $values = $row['values'];

            foreach (self::REQUIRED as $column) {
                if (($values[$column] ?? '') === '') {
                    $problems[] = __('Row :row: :column is empty.', ['row' => $row['row'], 'column' => $column]);

                    continue 2;
                }
            }

            $key = mb_strtolower(($values['supplier_vat_number'] ?? '') ?: $values['supplier_name']).'|'.$values['invoice_number'].'|'.($values['document_type'] ?? 'invoice');
            $groups[$key][] = $row;
        }

        if ($problems !== []) {
            return ['recorded' => 0, 'problems' => $problems];
        }

        $recorded = 0;

        try {
            DB::transaction(function () use ($organization, $groups, &$problems, &$recorded): void {
                foreach ($groups as $rows) {
                    $first = $rows[0]['values'];

                    try {
                        $this->purchases->record($organization, $this->entry($organization, $rows), null, Channel::Import);
                        $recorded++;
                    } catch (ValidationException $e) {
                        foreach ($e->errors() as $messages) {
                            $problems[] = __('Rows from :row (:number): :problem', ['row' => $rows[0]['row'], 'number' => $first['invoice_number'], 'problem' => $messages[0]]);
                        }
                    }
                }

                if ($problems !== []) {
                    throw new RuntimeException('rolled back');
                }
            });
        } catch (RuntimeException $e) {
            if ($problems === []) {
                throw $e;
            }

            return ['recorded' => 0, 'problems' => $problems];
        }

        return ['recorded' => $recorded, 'problems' => []];
    }

    /**
     * @param  list<array{row: int, values: array<string, string>}>  $rows
     *
     * @throws ValidationException
     */
    private function entry(Organization $organization, array $rows): PurchaseEntry
    {
        $first = $rows[0]['values'];

        $date = $this->date($first['invoice_date'], 'invoice_date');
        $received = isset($first['received_on']) ? $this->date($first['received_on'], 'received_on') : null;
        $due = isset($first['due_on']) ? $this->date($first['due_on'], 'due_on') : null;

        $lines = [];

        foreach ($rows as $row) {
            $values = $row['values'];
            $treatment = PurchaseTreatment::tryFrom(mb_strtolower($values['treatment']))
                ?? throw ValidationException::withMessages(['treatment' => __('Row :row: the treatment is one of :values.', ['row' => $row['row'], 'values' => implode(', ', array_column(PurchaseTreatment::cases(), 'value'))])]);
            $lines[] = new PurchaseLineEntry(
                description: $values['description'],
                treatment: $treatment,
                net: $values['net'],
                vat: $values['vat'] ?? null,
                vatRate: $values['vat_rate'] ?? null,
                capitalAsset: in_array(mb_strtolower($values['capital_asset'] ?? ''), ['yes', 'y', '1', 'true'], true),
                goodsType: $values['goods_type'] ?? null,
            );
        }

        return new PurchaseEntry(
            supplierId: (string) $this->supplier($organization, $first)->id,
            supplierInvoiceNumber: $first['invoice_number'],
            invoiceDate: $date,
            currency: $first['currency'] ?? $this->country->currency($organization),
            lines: $lines,
            exchangeRate: $first['exchange_rate'] ?? null,
            deductibleShare: $first['deductible_share'] ?? '100',
            documentType: ($first['document_type'] ?? 'invoice') === 'credit_note' ? 'credit_note' : 'invoice',
            receivedOn: $received,
            dueOn: $due,
            paidLater: in_array(mb_strtolower($first['paid_later'] ?? ''), ['yes', 'y', '1', 'true'], true),
        );
    }

    /** @throws ValidationException */
    private function date(string $value, string $field): CarbonImmutable
    {
        try {
            return CarbonImmutable::createFromFormat('!Y-m-d', $value) ?: throw new DomainException;
        } catch (Throwable) {
            throw ValidationException::withMessages([$field => __('The date is written as YYYY-MM-DD.')]);
        }
    }

    /**
     * @param  array<string, string>  $values
     */
    private function supplier(Organization $organization, array $values): Supplier
    {
        $existing = $this->suppliers->match((string) $organization->id, $values['supplier_vat_number'] ?? null, $values['supplier_name']);

        if ($existing !== null) {
            return $existing;
        }

        $supplier = new Supplier([
            'org_id' => (string) $organization->id,
            'name' => mb_substr($values['supplier_name'], 0, 200),
            'vat_number' => $values['supplier_vat_number'] ?? null,
            'country_code' => isset($values['supplier_country']) ? strtoupper(mb_substr($values['supplier_country'], 0, 2)) : null,
        ]);
        $supplier->forceFill(['created_via' => Channel::Import->value])->save();

        return $supplier;
    }
}
