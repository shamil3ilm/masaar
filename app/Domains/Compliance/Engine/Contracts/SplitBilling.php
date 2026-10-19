<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Engine\Contracts;

/**
 * One sale billed in two shares - the patient's (a co-payment or a
 * deductible) and the insurer's - each a document of its own
 * (InsuranceSplits).
 *
 * Shared code divides the sale and links the documents; the country says
 * whether it bills a sale this way at all, which kind of document each
 * share is, and how a line is taxed once the insurer is its buyer.
 */
interface SplitBilling
{
    /**
     * Why a sale is not billed in two shares here, in the reader's language
     * and citing the country's own documents; null where it is.
     */
    public function unavailable(): ?string;

    /** The invoice type (InvoiceType's value) of the patient's share. */
    public function patientType(): string;

    /** The invoice type of the insurer's share, invoiced to it as a registered buyer. */
    public function insurerType(): string;

    /**
     * A line of the insurer's share, taxed as it is when the insurer buys
     * it: a treatment that depends on who the buyer is - a citizen's zero
     * rating - does not go with the line to the insurer.
     *
     * @param  array<string, mixed>  $line
     * @return array<string, mixed>
     */
    public function insurerLine(array $line): array;
}
