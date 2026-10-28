<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Contracts;

/**
 * An invoice's latest filing with its tax authority, as shared code reads it.
 *
 * A country that files invoice by invoice keeps its own record of each
 * attempt; Saudi Arabia's is its InvoiceSubmission. The pipeline answers a
 * caller with where the latest one stands, and asked the Saudi model for it
 * directly, so the Invoice domain imported a country's model to describe its
 * own invoice. The country's module registers the relation
 * (Invoice::latestFiling()) and its model answers through this.
 */
interface FilingRecord
{
    /**
     * Where the filing stands, for an API answer.
     *
     * @return array{submission_id: string, state: string, clearance_status: ?string, reporting_status: ?string}
     */
    public function filingSummary(): array;

    /** Why it was last refused or failed, as the authority or the platform put it. */
    public function filingError(): ?string;
}
