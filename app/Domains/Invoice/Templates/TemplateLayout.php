<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Templates;

/**
 * How a printed invoice is laid out.
 *
 * Classic, modern and compact are sheet layouts - A4, Letter, A5 - and
 * thermal is the till's receipt: one black-and-white column. A roll holds
 * only that column, so on an 80 or 58 mm roll every layout prints the
 * receipt; thermal chosen for a sheet prints the receipt's column on it.
 */
enum TemplateLayout: string
{
    /** Today's page: the title and the parties, the lines, the totals. */
    case Classic = 'classic';

    /** A coloured band across the head, the parties in boxes. */
    case Modern = 'modern';

    /** Smaller type and tighter rows, for invoices with many lines. */
    case Compact = 'compact';

    /** The till's receipt, in black and white. */
    case Thermal = 'thermal';

    public function label(): string
    {
        return match ($this) {
            self::Classic => __('Classic'),
            self::Modern => __('Modern'),
            self::Compact => __('Compact'),
            self::Thermal => __('Thermal receipt'),
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Classic => __('The title and the parties, then the lines and the totals.'),
            self::Modern => __('A band in your colour across the head, and the parties in boxes.'),
            self::Compact => __('Smaller type and tighter rows, for invoices with many lines.'),
            self::Thermal => __('The till\'s receipt in one column, in black and white.'),
        };
    }
}
