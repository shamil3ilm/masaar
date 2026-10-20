<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\UnitedArabEmirates\DTOs;

use App\Domains\Compliance\Countries\UnitedArabEmirates\Enums\PintDocumentCategory;

/**
 * A PINT invoice or credit note, every value decided, ready to serialise.
 *
 * PintMapper fills it from an invoice, MandatoryFields judges it and
 * PintUblBuilder writes it; none of the three reaches back into the models,
 * so what is checked is exactly what is written.
 *
 * Amounts are decimal strings in the document currency, except the two
 * *InTaxCurrency totals, which are in dirhams and set only when the
 * document currency is not.
 */
final readonly class PintDocument
{
    /**
     * @param  list<string>  $billingReferences  Preceding invoice numbers (IBT-025).
     * @param  list<PintAllowance>  $allowances
     * @param  list<PintTaxSubtotal>  $subtotals
     * @param  list<PintLine>  $lines
     */
    public function __construct(
        public PintDocumentCategory $category,
        public string $customizationId,
        public string $profileId,
        public string $typeCode,
        public string $transactionFlags,
        public string $number,
        public string $uuid,
        public string $issueDate,
        public ?string $dueDate,
        public ?string $taxPointDate,
        public ?string $periodStart,
        public ?string $periodEnd,
        public string $currency,
        public ?string $taxCurrency,
        public ?string $exchangeRate,
        public array $billingReferences,
        public ?string $creditReason,
        public PintParty $seller,
        public PintParty $buyer,
        public ?string $beneficiaryId,
        public ?string $principalId,
        public ?PintAddress $delivery,
        public ?string $paymentMeansCode,
        public ?string $paymentAccount,
        public array $allowances,
        public array $subtotals,
        public string $lineExtensionAmount,
        public string $allowanceTotal,
        public string $taxExclusiveAmount,
        public string $taxAmount,
        public string $taxInclusiveAmount,
        public string $payableAmount,
        public ?string $taxAmountInTaxCurrency,
        public ?string $totalInTaxCurrency,
        public array $lines,
        // IBT-010, IBT-013 and IBT-012: the buyer's reference, purchase
        // order and contract.
        public ?string $buyerReference = null,
        public ?string $orderReference = null,
        public ?string $contractReference = null,
        // IBT-020, the payment terms in words.
        public ?string $paymentTerms = null,
        // IBG-21, the charges on the whole document, and IBT-108, their sum.
        /** @var list<PintCharge> */
        public array $charges = [],
        public string $chargeTotal = '0.00',
        // BTAE-06, how often a periodic supply is billed, in the invoicing
        // period; and the invoice note (IBT-022) that says it in words when
        // the code is Others (IBR-160-AE).
        public ?string $billingFrequency = null,
        public ?string $note = null,
    ) {}

    /**
     * One of the eight transaction-type flags (BTAE-02), by position.
     */
    public function hasFlag(int $position): bool
    {
        return ($this->transactionFlags[$position] ?? '0') === '1';
    }
}
