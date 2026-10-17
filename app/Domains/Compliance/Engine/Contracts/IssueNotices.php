<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Engine\Contracts;

/**
 * What a person is told after issuing from the portal, in the country's own
 * words. Part of how the portal shows the country (Presentation), kept a port
 * of its own because the issue action asks for it alone.
 */
interface IssueNotices
{
    /** Issued and filed, or handed over to be sent. */
    public function filed(): string;

    /** Issued, and not filed because the organization cannot file yet. */
    public function notFiled(): string;
}
