<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Enums;

/**
 * The paper an invoice is printed on.
 *
 * Three office sheets and two till rolls. A sheet has a fixed length and
 * holds a full-page layout; a roll has only a width, its length following the
 * receipt printed on it, and holds a single column. The widths below are the
 * printable ones a roll printer reaches, not the width of the paper: an 80 mm
 * roll prints 72 mm (576 dots at 203 dpi) and a 58 mm roll 48 mm (384 dots).
 */
enum PaperSize: string
{
    case A4 = 'a4';
    case Letter = 'letter';
    case A5 = 'a5';
    case Roll80 = '80mm';
    case Roll58 = '58mm';

    /** What a person picks from a list. */
    public function label(): string
    {
        return match ($this) {
            self::A4 => __('A4'),
            self::Letter => __('Letter'),
            self::A5 => __('A5'),
            self::Roll80 => __('80 mm receipt roll'),
            self::Roll58 => __('58 mm receipt roll'),
        };
    }

    public function isRoll(): bool
    {
        return $this === self::Roll80 || $this === self::Roll58;
    }

    /** The width of the paper, in millimetres. */
    public function widthMm(): float
    {
        return match ($this) {
            self::A4 => 210.0,
            self::Letter => 215.9,
            self::A5 => 148.0,
            self::Roll80 => 80.0,
            self::Roll58 => 58.0,
        };
    }

    /** The length of a sheet, in millimetres; null for a roll. */
    public function heightMm(): ?float
    {
        return match ($this) {
            self::A4 => 297.0,
            self::Letter => 279.4,
            self::A5 => 210.0,
            default => null,
        };
    }

    /**
     * The margin on every side, in millimetres. On a roll it is what keeps
     * the column inside the printable width: (80 - 72) / 2 and (58 - 48) / 2.
     */
    public function marginMm(): float
    {
        return match ($this) {
            self::A4 => 14.0,
            self::Letter => 12.7,
            self::A5 => 10.0,
            self::Roll80 => 4.0,
            self::Roll58 => 5.0,
        };
    }

    /**
     * The dots across a roll printer's head: the printable width at the
     * printer's resolution, in whole bytes of eight dots as the printer is
     * sent them - 576 and 384 at the usual 203 dpi. Null for a sheet.
     */
    public function printerDots(int $dpi = 203): ?int
    {
        $printable = match ($this) {
            self::Roll80 => 72.0,
            self::Roll58 => 48.0,
            default => null,
        };

        return $printable === null ? null : 8 * (int) round($printable / 25.4 * $dpi / 8);
    }
}
