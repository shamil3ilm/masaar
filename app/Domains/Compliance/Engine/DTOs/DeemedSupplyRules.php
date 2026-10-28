<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Engine\DTOs;

/**
 * A country's thresholds for deemed supplies, each as its law sets it.
 *
 *   recipientKinds      the kinds (DeemedSupplyKind values) relieved while
 *                       one recipient's total stays within recipientAllowance
 *   recipientAllowance  that total, in the tax currency
 *   window              how it is counted: 'calendar_year', or
 *                       'twelve_months' ending with the supply's month
 *   reliefCap           the most those relieved supplies may come to in the
 *                       window for the relief to apply at all; null for none
 *   taxCap              the output tax on all deemed supplies in the window
 *                       below which none is payable, only the excess; null
 *                       for none
 */
final readonly class DeemedSupplyRules
{
    /**
     * @param  list<string>  $recipientKinds
     */
    public function __construct(
        public array $recipientKinds,
        public string $recipientAllowance,
        public string $window,
        public ?string $reliefCap,
        public ?string $taxCap,
        public string $source,
    ) {}
}
