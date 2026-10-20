<?php

declare(strict_types=1);

namespace App\Domains\Billing\Reconciliation\Settlement;

use App\Domains\Billing\Reconciliation\Contracts\SettlementReport;
use App\Domains\Billing\Reconciliation\DTOs\ParsedStatement;
use App\Domains\Billing\Reconciliation\StatementRejected;

/**
 * No card gateway yet, so no settlement report to read.
 */
final class UnavailableSettlementReport implements SettlementReport
{
    public function available(): bool
    {
        return false;
    }

    public function accepts(): string
    {
        return '';
    }

    public function parse(string $bytes): ParsedStatement
    {
        throw new StatementRejected(__('There is no card gateway, so there is no settlement report to import.'));
    }
}
