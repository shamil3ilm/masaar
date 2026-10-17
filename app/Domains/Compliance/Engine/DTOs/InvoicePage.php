<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Engine\DTOs;

/**
 * What one invoice's portal page shows that depends on its country.
 *
 * The page itself - the buyer, the lines, the stored totals, the Issue
 * button that Access decides - is shared. Around it the country says what
 * the document is called, what can be done with it once issued, where it
 * stands with the authority or the network, and whether it may be emailed.
 * Each view is given the invoice and $data.
 */
final readonly class InvoicePage
{
    /**
     * @param  string  $heading  view: the kind of document, above its number
     * @param  string  $issueConfirm  what the Issue button asks before issuing
     * @param  string  $actions  view: what can be done with an invoice past draft
     * @param  string  $panel  view: where the invoice stands, beside it
     * @param  list<string>  $buyerDetails  lines under the buyer's name, such as its tax number
     * @param  string|null  $exchangeNote  the rate the tax was reported at, for an invoice in another currency
     * @param  bool  $shareable  whether the page offers to email the PDF to the buyer
     * @param  array<string, mixed>  $data  what the views read beyond the invoice
     */
    public function __construct(
        public string $heading,
        public string $issueConfirm,
        public string $actions,
        public string $panel,
        public array $buyerDetails,
        public ?string $exchangeNote,
        public bool $shareable,
        public array $data = [],
    ) {}
}
