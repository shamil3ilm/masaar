<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Engine\Contracts;

use App\Domains\Invoice\Models\Invoice;

/**
 * What a person or an integrator is told after issuing, in the country's own
 * words. Part of how the portal shows the country (Presentation), kept a port
 * of its own because the issue action asks for it alone.
 */
interface IssueNotices
{
    /** Issued and filed, or handed over to be sent. */
    public function filed(): string;

    /** Issued, and not filed because the organization cannot file yet. */
    public function notFiled(): string;

    /**
     * What the authority advised when it accepted the invoice: warnings that
     * did not stop it, each as "code: message". Empty when it advised
     * nothing, or the country's authority gives no advice.
     *
     * @return list<string>
     */
    public function advisories(Invoice $invoice): array;
}
