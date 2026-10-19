<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Templates;

/**
 * The account a buyer pays into, printed at the foot of the invoice.
 *
 * Stored as entered once checked: the IBAN in capitals without spaces
 * (Iban::normalise), the SWIFT code in capitals.
 */
final readonly class BankDetails
{
    public function __construct(
        public string $bank = '',
        public string $iban = '',
        public string $accountName = '',
        public string $swift = '',
    ) {}

    public function isEmpty(): bool
    {
        return $this->bank === '' && $this->iban === '' && $this->accountName === '' && $this->swift === '';
    }

    /** The IBAN in groups of four, as it is printed and read aloud. */
    public function formattedIban(): string
    {
        return trim(chunk_split($this->iban, 4, ' '));
    }
}
