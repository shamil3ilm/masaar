<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Engine\Contracts;

use App\Domains\Compliance\Engine\DTOs\ExemptionReason;
use App\Domains\Compliance\Engine\DTOs\VatCategory;

/**
 * How a line can be taxed in a country: its tax categories and the reasons a
 * line is zero-rated, exempt or out of scope, from the country's official
 * code lists.
 *
 * The portal's invoice, product and customer forms offer these, and the
 * invoice preview labels its VAT breakdown by them. Whether a combination is
 * acceptable is still the country's InvoiceRules: this is what is offered,
 * that is what is enforced.
 */
interface VatTreatments
{
    /**
     * The categories a line can be in, the standard one first.
     *
     * @return list<VatCategory>
     */
    public function categories(): array;

    /**
     * The reasons a line names for being in a category other than the
     * standard one, in the code list's order.
     *
     * @return list<ExemptionReason>
     */
    public function exemptions(): array;

    /** One reason by its code; null for a code the country does not have. */
    public function exemption(string $code): ?ExemptionReason;
}
