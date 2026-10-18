<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Services;

use App\Domains\Compliance\Engine\Services\JurisdictionResolver;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;
use App\Domains\Organization\Services\LanguageGrace;

/**
 * While an organization's grace period lasts (LanguageGrace), what an
 * invoice lacks of the text its country requires: the warnings the API and
 * the pipeline answer with, the portal's banner and the note on the
 * invoice's page.
 *
 * Without a grace period there is nothing to warn about - the country's
 * rules refuse such a draft before it is drafted - so this says nothing,
 * and a reply is what it always was. The country decides what is missing
 * (LanguageRules::missingText()); this only words it for a reply.
 */
class MissingText
{
    public function __construct(
        private readonly JurisdictionResolver $jurisdictions,
        private readonly LanguageGrace $grace,
    ) {}

    /**
     * By field, in words a person can act on; empty outside a grace period.
     *
     * @return array<string, string>
     */
    public function of(Invoice $invoice, ?Organization $organization): array
    {
        if (! $this->grace->active($organization)) {
            return [];
        }

        return $this->jurisdictions->forRules($organization)->languageRules()->missingText($invoice->loadMissing('lines'));
    }

    /**
     * As warnings: "field: what is missing".
     *
     * @return list<string>
     */
    public function warnings(Invoice $invoice, ?Organization $organization): array
    {
        $warnings = [];

        foreach ($this->of($invoice, $organization) as $field => $message) {
            $warnings[] = $field.': '.$message;
        }

        return $warnings;
    }
}
