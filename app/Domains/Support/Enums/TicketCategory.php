<?php

declare(strict_types=1);

namespace App\Domains\Support\Enums;

enum TicketCategory: string
{
    case Zatca = 'zatca';
    case Invoices = 'invoices';
    case Account = 'account';
    case Billing = 'billing';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Zatca => __('Tax authority connection or filing'),
            self::Invoices => __('Invoices'),
            self::Account => __('Sign-in and account'),
            self::Billing => __('Plan and billing'),
            self::Other => __('Something else'),
        };
    }
}
