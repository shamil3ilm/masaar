<?php

declare(strict_types=1);

namespace App\Domains\Support\Enums;

enum TicketPriority: string
{
    case Normal = 'normal';

    /** Invoicing or filing has stopped. */
    case Urgent = 'urgent';

    public function label(): string
    {
        return match ($this) {
            self::Normal => __('Normal'),
            self::Urgent => __('Urgent - invoicing has stopped'),
        };
    }
}
