<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Support;

use App\Domains\Invoice\Models\Invoice;

/**
 * Why a draft was not issued, as a person reads it.
 *
 * The issuing step words its refusals for an integrator - "Compliance
 * generation failed: Note draft 01a17e19-... cannot be issued: ..." - and
 * the API keeps those words. On the portal the prefix is jargon and the
 * draft's id means nothing, so both go; a sentence the platform knows in the
 * reader's language is said in it.
 */
final class IssueFailure
{
    private const PREFIX = 'Compliance generation failed: ';

    public static function plain(?string $error, Invoice $invoice): string
    {
        if (blank($error)) {
            return __('The invoice could not be issued.');
        }

        $reason = str_starts_with((string) $error, self::PREFIX) ? substr((string) $error, strlen(self::PREFIX)) : (string) $error;
        $reason = str_replace('draft '.$invoice->id, __('this draft'), $reason);

        return __('Not issued; it stays a draft. :reason', ['reason' => __($reason)]);
    }
}
