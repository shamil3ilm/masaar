<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Engine\Contracts;

/**
 * What the public website says and shows about a country.
 *
 * The home page asks every registered module and never which country it is,
 * so a country added to the engine appears there without a shared view
 * changing.
 *
 * What a module says is a plain statement of what it does, naming the
 * authority's or network's own terms; it claims no certification or
 * accreditation. Whether the country is switched on is the module's
 * enabled(), which the page shows beside it.
 */
interface Website
{
    /**
     * How the country's invoices are filed, in a sentence, in the reader's
     * language: the system, and what happens to an invoice in it.
     */
    public function offering(): string;

    /**
     * The published standards the country's documents are built to, by
     * their own names, in the reader's language where they have one.
     *
     * @return list<string>
     */
    public function standards(): array;

    /**
     * The invoice the public developer page's quick start submits through
     * the partner pipeline (POST /api/v1/pipeline/submit): a small one the
     * country's rules accept, dated $today, as its JSON fields.
     * DocumentedExamplesTest sends it, so the page cannot teach an invoice
     * the API refuses.
     *
     * @param  string  $today  the date of issue, year-month-day
     * @return array<string, mixed>
     */
    public function apiExample(string $today): array;
}
