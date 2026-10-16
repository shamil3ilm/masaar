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
            self::Zatca => 'ZATCA connection or filing',
            self::Invoices => 'Invoices',
            self::Account => 'Sign-in and account',
            self::Billing => 'Plan and billing',
            self::Other => 'Something else',
        };
    }
}
