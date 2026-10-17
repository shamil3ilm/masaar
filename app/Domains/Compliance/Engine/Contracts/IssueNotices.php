<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Engine\Contracts;

/**
 * What a person is told after issuing from the portal, in the country's own
 * words. The first of the presentation port a later phase builds out.
 */
interface IssueNotices
{
    /** Issued and filed, or handed over to be sent. */
    public function filed(): string;

    /** Issued, and not filed because the organization cannot file yet. */
    public function notFiled(): string;
}
