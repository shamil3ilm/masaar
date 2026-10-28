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

    /**
     * One of advisories() as a person reads it: a sentence in the reader's
     * language, for the portal to show above the authority's own text.
     */
    public function explainAdvisory(string $advisory): string;

    /**
     * Each rule the authority said a rejected invoice broke, as it named it:
     * code, category and message. Empty unless it was rejected.
     *
     * @return list<array{code: ?string, category: ?string, message: string}>
     */
    public function rejections(Invoice $invoice): array;

    /**
     * One of rejections() as a person reads it: what the rule asks, in the
     * reader's language, for the portal to show above the authority's words.
     */
    public function explainRejection(?string $code, string $message): string;
}
