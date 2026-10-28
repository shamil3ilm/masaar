<?php

declare(strict_types=1);

namespace App\Domains\Books\Enums;

/** How a customer paid an invoice. */
enum PaymentMethod: string
{
    case BankTransfer = 'bank_transfer';
    case Cash = 'cash';
    case Card = 'card';
    case Cheque = 'cheque';
    // A voucher the organization sold, redeemed against the invoice (Vouchers).
    case Voucher = 'voucher';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::BankTransfer => __('Bank transfer'),
            self::Cash => __('Cash'),
            self::Card => __('Card'),
            self::Cheque => __('Cheque'),
            self::Voucher => __('Voucher'),
            self::Other => __('Other'),
        };
    }

    /**
     * The ways a payment is recorded by hand: a voucher is used through
     * Vouchers, which records its payment itself.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (array_filter(self::cases(), fn (self $case): bool => $case !== self::Voucher) as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
