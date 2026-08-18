<?php

declare(strict_types=1);

namespace App\Domains\Compliance\FTA\Services;

use App\Domains\Compliance\FTA\DTOs\FtaInvoiceData;
use App\Domains\Compliance\FTA\Exceptions\FtaException;

/**
 * Validates invoice data against UAE FTA Peppol PINT AE rules.
 */
class FtaValidator
{
    private const ALLOWED_CURRENCIES = ['AED'];

    private const ALLOWED_DOC_TYPES = ['380', '381', '383'];

    private const VAT_RATE_STANDARD = 0.05;

    private const VAT_RATE_ZERO = 0.00;

    /** @throws FtaException */
    public function validate(FtaInvoiceData $data): void
    {
        $this->validateTrn($data->supplierTrn);

        if ($data->customerTrn !== null) {
            $this->validateTrn($data->customerTrn);
        }

        if (! in_array($data->currencyCode, self::ALLOWED_CURRENCIES, true)) {
            throw new FtaException("Currency '{$data->currencyCode}' is not accepted by UAE FTA. Use AED.");
        }

        if (! in_array($data->documentType, self::ALLOWED_DOC_TYPES, true)) {
            throw new FtaException("Document type '{$data->documentType}' is invalid. Use 380 (invoice), 381 (credit note), 383 (debit note).");
        }

        if (in_array($data->documentType, ['381', '383'], true) && empty($data->creditNoteReference)) {
            throw new FtaException('Credit/debit notes must reference the original invoice number.');
        }

        if (! in_array($data->vatRate, [self::VAT_RATE_STANDARD, self::VAT_RATE_ZERO], true)) {
            throw new FtaException("VAT rate {$data->vatRate} is not valid under UAE regulations. Use 0.05 or 0.00.");
        }

        $this->validateAmounts($data);
    }

    /** UAE TRN: exactly 15 digits. */
    public function validateTrn(string $trn): void
    {
        if (! preg_match('/^\d{15}$/', $trn)) {
            throw FtaException::invalidTrn($trn);
        }
    }

    private function validateAmounts(FtaInvoiceData $data): void
    {
        $expectedTaxInclusive = round($data->taxExclusiveAmount + $data->vatAmount, 2);

        if (abs($expectedTaxInclusive - $data->taxInclusiveAmount) > 0.01) {
            throw new FtaException(
                "Tax-inclusive amount mismatch. Expected {$expectedTaxInclusive}, got {$data->taxInclusiveAmount}."
            );
        }

        if ($data->payableAmount <= 0) {
            throw new FtaException('Payable amount must be greater than zero.');
        }
    }
}
