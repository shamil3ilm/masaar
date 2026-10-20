<?php

declare(strict_types=1);

namespace App\Domains\Billing\Reconciliation\Parsers;

use App\Domains\Billing\Reconciliation\DTOs\ParsedLine;
use App\Domains\Billing\Reconciliation\StatementRejected;

/**
 * A statement that states its opening and closing balances must add up:
 * the opening, plus what came in, less what went out, is the closing. One
 * that does not is cut short or altered, and is refused whole rather than
 * half believed.
 */
final class BalanceCheck
{
    /**
     * @param  list<ParsedLine>  $lines
     *
     * @throws StatementRejected
     */
    public static function assert(?string $opening, ?string $closing, array $lines): void
    {
        if ($opening === null || $closing === null) {
            return;
        }

        $sum = $opening;

        foreach ($lines as $line) {
            $sum = $line->direction === ParsedLine::CREDIT ? bcadd($sum, $line->amount, 2) : bcsub($sum, $line->amount, 2);
        }

        if (bccomp($sum, $closing, 2) !== 0) {
            throw new StatementRejected(__('The statement does not add up: its opening balance and lines come to :sum, and it says it closes at :closing. It may be cut short.', ['sum' => $sum, 'closing' => $closing]));
        }
    }
}
