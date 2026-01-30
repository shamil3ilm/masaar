<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Enums;

/**
 * ZATCA invoice types.
 *
 * Standard (B2B): Requires clearance
 * Simplified (B2C): Requires reporting only
 */
enum InvoiceType: string
{
    case Standard = 'standard';     // B2B - clearance required
    case Simplified = 'simplified'; // B2C - reporting only

    /**
     * Check if invoice requires ZATCA clearance.
     */
    public function requiresClearance(): bool
    {
        return $this === self::Standard;
    }
}
