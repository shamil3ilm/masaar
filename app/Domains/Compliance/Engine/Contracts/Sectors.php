<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Engine\Contracts;

/**
 * Which of the platform's business types (the admin-editable list,
 * BusinessTypes) a country's e-invoicing serves.
 *
 * Asked when a type is offered - at sign-up and on the company page - and
 * never when anything is issued: a type sets defaults only, so this decides
 * what is suggested, not what is allowed. A type the module does not know,
 * one an admin added, is served.
 */
interface Sectors
{
    /**
     * The types the country's mandate leaves out entirely, by key: not
     * offered to an organization there.
     *
     * @return list<string>
     */
    public function omitted(): array;

    /**
     * What a person choosing the type should know about it in this country,
     * in their language, citing the country's own documents; null when
     * nothing.
     */
    public function note(string $key): ?string;
}
