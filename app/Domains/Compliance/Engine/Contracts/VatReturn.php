<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Engine\Contracts;

use App\Domains\Compliance\Engine\DTOs\BadDebtRules;
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
     * How many months after its supply a purchase not paid in full gives
     * back the input tax on what is unpaid, reclaimed as it is paid; null
     * where the return has no such rule.
     */
    public function unpaidInputTaxMonths(): ?int;

    /**
     * When output tax on an unpaid sale may be taken back, and what must be
     * held to do so; null where the return has no such relief.
     */
    public function badDebtRules(): ?BadDebtRules;

    /**
     * The goods a registered buyer accounts for itself when it buys them
     * from a registered supplier in the country (domestic reverse charge),
     * code => name in English; empty where the country has none, or the
     * organization is not registered for VAT.
     *
     * @return array<string, string>
     */
    public function domesticReverseChargeGoods(string $organizationId): array;

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
