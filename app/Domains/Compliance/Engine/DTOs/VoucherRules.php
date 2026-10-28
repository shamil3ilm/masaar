<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Engine\DTOs;

/**
 * How a country taxes a voucher with a face value: whether selling one is a
 * supply (and so makes a tax invoice), and the text that says so. A voucher
 * used to pay for goods or services is consideration for that supply, which
 * its own invoice taxes.
 */
final readonly class VoucherRules
{
    public function __construct(
        public bool $saleIsSupply,
        public string $source,
    ) {}
}
