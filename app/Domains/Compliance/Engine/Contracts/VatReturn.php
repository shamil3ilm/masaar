<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Engine\Contracts;

use App\Domains\Compliance\Engine\DTOs\VatReturnDraft;
use Carbon\CarbonImmutable;

/**
 * A draft of the country's VAT return for a period, from the organization's
 * sales and the purchases it recorded in Books: every box the return form
 * has, in the country's tax currency, each with the documents behind it.
 *
 * A draft for the organization to check and file itself: the platform files
 * no return. What the books cannot know - a correction of an earlier
 * return, credit carried forward - is asked for (manualEntries) and taken
 * as given.
 */
interface VatReturn
{
    /**
     * Whether input tax waits on paying the supplier, so the books ask when
     * a purchase will be paid and record what was paid on it.
     */
    public function inputTaxOnPayment(): bool;

    /**
     * The figures the return asks for that the books do not hold, key =>
     * label: typed in on the page and passed to draft().
     *
     * @return array<string, string>
     */
    public function manualEntries(): array;

    /**
     * @param  array<string, float>  $manual  by manualEntries() key
     */
    public function draft(string $organizationId, CarbonImmutable $from, CarbonImmutable $to, array $manual = []): VatReturnDraft;
}
