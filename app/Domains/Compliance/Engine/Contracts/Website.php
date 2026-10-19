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
}
