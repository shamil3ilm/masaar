<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\UnitedArabEmirates\Reports;

use App\Domains\Compliance\Countries\UnitedArabEmirates\Document\PintMapper;
use App\Domains\Compliance\Countries\UnitedArabEmirates\Enums\PintTaxCategory;
use App\Domains\Compliance\Countries\UnitedArabEmirates\Rules\PintAe;
use App\Domains\Invoice\Enums\DocumentType;
use App\Domains\Invoice\Services\VatSummary;
use Carbon\CarbonImmutable;
use DomainException;

/**
 * A UAE organization's sales for a period by UAE tax category, in dirhams.
 *
 * A working summary for the organization and its accountant, not the VAT
 * return form: the return's boxes split sales by emirate and ask for
 * purchases, neither of which this gives. Every document issued in the
 * period counts, at its tax point as VatSummary reads it; credit notes are
 * taken off. Each document's breakdown is the one its e-invoice carries
 * (PintMapper::breakdownOf), so reverse-charge and margin-scheme lines show
 * an amount and no VAT, as the e-invoice does.
 *
 * A document in another currency is converted at its own rate to dirhams;
 * one without a rate is left out and named, as converting at 1 would
 * misstate it.
 */
class UaeVatSummary
{
    /** PintTaxCategory, as the summary names each. The portal translates them. */
    public const CATEGORIES = [
        'S' => 'Standard rate',
        'Z' => 'Zero-rated',
        'E' => 'Exempt',
        'O' => 'Out of scope',
        'AE' => 'Reverse charge',
        'N' => 'Margin scheme',
    ];

    public function __construct(
        private readonly VatSummary $vat,
        private readonly PintMapper $mapper,
        private readonly PintAe $profile,
    ) {}

    /** The currency the summary is in: the UAE's tax currency. */
    public function currency(): string
    {
        return $this->profile->taxCurrency();
    }

    /**
     * What each document adds, one tax category at a time, in dirhams and
     * signed: the rows for() sums, so a figure can be traced back to its
     * documents. A document left out yields one row naming why: no rate, or
     * a breakdown the UAE cannot read. First marks a document's first row;
     * code is null on a row that adds nothing.
     *
     * @return \Generator<int, array{invoice: object, code: string|null, sign: int, rate: float|null, first: bool, problem: string|null, base: float, vat: float}>
     */
    public function contributions(string $organizationId, CarbonImmutable $from, CarbonImmutable $to): \Generator
    {
        $currency = $this->currency();

        foreach ($this->vat->issued($organizationId, $from, $to) as $invoice) {
            $rate = $this->vat->rate($invoice, $currency);
            $sign = $invoice->document_type === DocumentType::CreditNote ? -1 : 1;
            $none = ['invoice' => $invoice, 'code' => null, 'sign' => $sign, 'rate' => $rate, 'first' => true, 'base' => 0.0, 'vat' => 0.0];

            if ($rate === null) {
                yield [...$none, 'problem' => 'rate'];

                continue;
            }

            // An invoice whose lines the UAE breakdown cannot read - a tax
            // category it does not use - is listed for attention, not
            // allowed to take the whole report down with it.
            try {
                $breakdown = $this->mapper->breakdownOf($invoice, $this->profile);
            } catch (DomainException) {
                yield [...$none, 'problem' => 'unreadable'];

                continue;
            }

            $first = true;

            foreach ($breakdown as $subtotal) {
                yield [
                    'invoice' => $invoice,
                    'code' => $subtotal->category->value,
                    'sign' => $sign,
                    'rate' => $rate,
                    'first' => $first,
                    'problem' => null,
                    'base' => $sign * (float) $subtotal->taxableAmount * $rate,
                    'vat' => $sign * (float) $subtotal->taxAmount * $rate,
                ];
                $first = false;
            }

            if ($first) {
                yield [...$none, 'problem' => null];
            }
        }
    }

    /**
     * @return array{
     *     currency: string,
     *     categories: array<string, array{label: string, base: float, vat: float}>,
     *     total_base: float,
     *     total_vat: float,
     *     documents: int,
     *     credit_notes: int,
     *     converted: int,
     *     missing_rate: list<string>,
     *     unreadable: list<string>
     * }
     */
    public function for(string $organizationId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $currency = $this->currency();
        $categories = [];

        foreach (PintTaxCategory::cases() as $category) {
            $categories[$category->value] = ['label' => self::CATEGORIES[$category->value], 'base' => 0.0, 'vat' => 0.0];
        }

        $documents = 0;
        $creditNotes = 0;
        $converted = 0;
        $missingRate = [];
        $unreadable = [];

        foreach ($this->contributions($organizationId, $from, $to) as $row) {
            if ($row['problem'] === 'rate') {
                $missingRate[] = (string) $row['invoice']->invoice_number;

                continue;
            }

            if ($row['problem'] === 'unreadable') {
                $unreadable[] = (string) $row['invoice']->invoice_number;

                continue;
            }

            if ($row['first']) {
                $documents++;
                $converted += $row['rate'] !== 1.0 ? 1 : 0;
                $creditNotes += $row['sign'] < 0 ? 1 : 0;
            }

            if ($row['code'] !== null) {
                $categories[$row['code']]['base'] += $row['base'];
                $categories[$row['code']]['vat'] += $row['vat'];
            }
        }

        foreach ($categories as $code => $values) {
            $categories[$code]['base'] = round($values['base'], 2);
            $categories[$code]['vat'] = round($values['vat'], 2);
        }

        return [
            'currency' => $currency,
            'categories' => $categories,
            'total_base' => round(array_sum(array_column($categories, 'base')), 2),
            'total_vat' => round(array_sum(array_column($categories, 'vat')), 2),
            'documents' => $documents,
            'credit_notes' => $creditNotes,
            'converted' => $converted,
            'missing_rate' => $missingRate,
            'unreadable' => $unreadable,
        ];
    }
}
