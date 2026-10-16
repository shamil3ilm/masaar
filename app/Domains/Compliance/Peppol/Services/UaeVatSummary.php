<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Peppol\Services;

use App\Domains\Compliance\Peppol\Enums\PintTaxCategory;
use App\Domains\Compliance\Peppol\Profiles\PintAe;
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

        foreach ($this->vat->issued($organizationId, $from, $to) as $invoice) {
            $rate = $this->vat->rate($invoice, $currency);

            if ($rate === null) {
                $missingRate[] = (string) $invoice->invoice_number;

                continue;
            }

            // An invoice whose lines the UAE breakdown cannot read - a tax
            // category it does not use - is listed for attention, not
            // allowed to take the whole report down with it.
            try {
                $breakdown = $this->mapper->breakdownOf($invoice, $this->profile);
            } catch (DomainException) {
                $unreadable[] = (string) $invoice->invoice_number;

                continue;
            }

            $documents++;
            $converted += $rate !== 1.0 ? 1 : 0;
            $sign = $invoice->document_type === DocumentType::CreditNote ? -1 : 1;
            $creditNotes += $sign < 0 ? 1 : 0;

            foreach ($breakdown as $subtotal) {
                $code = $subtotal->category->value;
                $categories[$code]['base'] += $sign * (float) $subtotal->taxableAmount * $rate;
                $categories[$code]['vat'] += $sign * (float) $subtotal->taxAmount * $rate;
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
