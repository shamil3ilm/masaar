<?php

declare(strict_types=1);

namespace App\Domains\Billing\Reconciliation\Parsers;

use App\Domains\Billing\Reconciliation\StatementRejected;

/**
 * A statement file's bytes as UTF-8 text, or refused.
 *
 * A byte-order mark says what the file is (UTF-8, or UTF-16 as some
 * spreadsheet exports write it); without one the file is read in the
 * encoding its bank's profile names (UTF-8 unless it says otherwise, such
 * as Windows-1256 for an Arabic export). A file that is not text at all -
 * a spreadsheet saved as .xlsx, an image - or not valid in that encoding
 * is refused rather than read as garbage.
 */
final class TextFile
{
    /** What a profile may say its bank writes. */
    public const ENCODINGS = ['UTF-8', 'Windows-1256', 'ISO-8859-1', 'Windows-1252'];

    /**
     * @throws StatementRejected
     */
    public static function decode(string $bytes, string $encoding = 'UTF-8'): string
    {
        if (str_starts_with($bytes, "\xEF\xBB\xBF")) {
            $bytes = substr($bytes, 3);
            $encoding = 'UTF-8';
        } elseif (str_starts_with($bytes, "\xFF\xFE") || str_starts_with($bytes, "\xFE\xFF")) {
            $bytes = self::convert(substr($bytes, 2), str_starts_with($bytes, "\xFF\xFE") ? 'UTF-16LE' : 'UTF-16BE');
            $encoding = 'UTF-8';
        }

        if (str_contains($bytes, "\0")) {
            throw new StatementRejected(__('The file is not a text file. Export the statement as text (CSV, MT940 or XML) and upload that.'));
        }

        if (! in_array($encoding, self::ENCODINGS, true)) {
            throw new StatementRejected(__('The file\'s encoding is not one that can be read.'));
        }

        if ($encoding !== 'UTF-8') {
            $bytes = self::convert($bytes, $encoding);
        }

        if (! mb_check_encoding($bytes, 'UTF-8')) {
            throw new StatementRejected(__('The file is not UTF-8 text. Set the encoding the bank writes in its profile, or export it again as UTF-8.'));
        }

        return str_replace(["\r\n", "\r"], "\n", $bytes);
    }

    /** @throws StatementRejected */
    private static function convert(string $bytes, string $from): string
    {
        $converted = @iconv($from, 'UTF-8', $bytes);

        if ($converted === false) {
            throw new StatementRejected(__('The file is not valid :encoding text.', ['encoding' => $from]));
        }

        return $converted;
    }
}
