<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\SaudiArabia\Rules;

use App\Domains\Invoice\Enums\DocumentType;
use App\Domains\Invoice\Services\PrepaymentSettlement;
use App\Domains\Invoice\Support\ChargeRules;
use App\Domains\Organization\Services\LanguageGrace;
use App\Domains\Organization\Services\TenantResolver;
use Illuminate\Http\Request;
use Illuminate\Validation\Validator;

/**
 * A Saudi draft checked as a whole, after its request rules: the buyer a
 * citizen's zero rating names, the discount, the transaction types, the
 * prepayments it settles and the Arabic it carries.
 */
final class DraftChecks
{
    public function __construct(
        private readonly TenantResolver $tenant,
        private readonly PrepaymentSettlement $settlement,
        private readonly LanguageGrace $grace,
    ) {}

    /**
     * BR-KSA-49: a line zero-rated as private healthcare or private
     * education to a citizen (VATEX-SA-HEA, VATEX-SA-EDU) names the buyer by
     * national ID - the other buyer ID (BT-46) with scheme NAT (BT-46-1).
     * The rate depends on who the buyer is, so the invoice says who.
     */
    public function citizenBuyer(Validator $validator, Request $request): void
    {
        $code = collect((array) $request->input('lines', []))
            ->map(fn ($line) => is_array($line) ? ($line['exempt_code'] ?? null) : null)
            ->first(fn ($code): bool => in_array($code, FatooraConfig::NAMED_BUYER_EXEMPTIONS, true));

        if ($code === null) {
            return;
        }

        if ($request->input('buyer_id_scheme') !== FatooraConfig::CITIZEN_BUYER_SCHEME) {
            $validator->errors()->add('buyer_id_scheme', __(':code is zero-rated only for a citizen: identify the buyer by national ID (BR-KSA-49).', ['code' => $code]));
        }

        if (! $request->filled('buyer_id')) {
            $validator->errors()->add('buyer_id', __(':code needs the buyer national ID number (BR-KSA-49).', ['code' => $code]));
        }
    }

    /**
     * The Arabic the invoice must carry (ArabicText), refused in both
     * languages - unless a platform admin has given the organization a
     * grace period, when the draft is taken and the missing text is
     * flagged wherever it is shown (LanguageRules::missingText()).
     */
    public function arabicText(Validator $validator, Request $request): void
    {
        if ($this->grace->active($this->tenant->getOrganization())) {
            return;
        }

        foreach (ArabicText::missing($request->all()) as $field => $message) {
            $validator->errors()->add($field, $message);
        }
    }

    /**
     * A final tax invoice settles prepayment invoices (BR-KSA-73): a note
     * or another prepayment does not, and each one named must be this
     * organization's, issued, in this currency and not settled already.
     */
    public function prepayments(Validator $validator, Request $request): void
    {
        $references = $request->input('prepayments');

        if (! is_array($references) || $references === [] || $validator->errors()->has('prepayments.*')) {
            return;
        }

        if (($request->input('document_type') ?? 'invoice') !== DocumentType::Invoice->value) {
            $validator->errors()->add('prepayments', __('Only a final tax invoice settles prepayments (BR-KSA-73).'));

            return;
        }

        $organizationId = $this->tenant->getOrganizationId();

        foreach ($references as $index => $reference) {
            $found = $organizationId === null ? __('There is no invoice :number.', ['number' => $reference['invoice_number'] ?? ''])
                : $this->settlement->settleable($organizationId, (string) ($reference['invoice_number'] ?? ''), (string) $request->input('currency', FatooraConfig::CURRENCY_SAR), $request->only(['buyer_vat_number', 'buyer_id', 'buyer_id_scheme']));

            if (is_string($found)) {
                $validator->errors()->add("prepayments.{$index}.invoice_number", $found);
            }
        }
    }

    /**
     * The transaction types ZATCA refuses together, from the Schematron in
     * its SDK (20210819_ZATCA_E-invoice_Validation_Rules.xsl) and its XML
     * Implementation Standard (v1.2, May 2023):
     *
     *   BR-KSA-07  an export is not self-billed.
     *   BR-KSA-31  a simplified invoice is not self-billed, and not an
     *              export. The rule's compiled test checks only position 7,
     *              so the SDK passes a simplified export; but its stated
     *              test, the assertion the SDK reports, requires position 5
     *              to be 0, and the Standard (p55) accepts only third party,
     *              nominal and summary on a simplified invoice. The written
     *              rule is the authority and the compiled one a defect, so a
     *              simplified export stays refused: an export is invoiced on
     *              a tax invoice, as ZATCA's export sample is.
     *   BR-KSA-46  an export names no buyer VAT number (BT-48) - the
     *              Standard's rule (p57), which the SDK does not yet compile.
     *   BR-KSA-71  a simplified summary invoice names its buyer, and
     *   BR-KSA-72  states the period it summarises.
     *
     * Nothing ties an export's lines to particular tax categories or
     * exemption codes: the SDK accepts Z with any zero-rating code -
     * VATEX-SA-32 and 33 for exports of goods and services, 34-1 to 34-5 for
     * international transport, 35, 36 and the rest - and E, O and S lines
     * too, each checked as on any invoice (BR-KSA-18, BR-KSA-CL-04,
     * BR-KSA-23, BR-KSA-24, BR-KSA-69). So the line rules apply as they do
     * everywhere, and an export is refused only for what ZATCA refuses.
     */
    public function transactionFlags(Validator $validator, Request $request): void
    {
        $errors = $validator->errors();
        $simplified = $request->input('type') === 'simplified';

        if ($request->boolean('is_export') && $request->boolean('is_self_billed')) {
            $errors->add('is_self_billed', __('An export invoice cannot be self-billed (BR-KSA-07).'));
        }

        if ($simplified && $request->boolean('is_self_billed')) {
            $errors->add('is_self_billed', __('A simplified invoice cannot be self-billed (BR-KSA-31).'));
        }

        if ($simplified && $request->boolean('is_export')) {
            $errors->add('is_export', __('An export is invoiced on a standard tax invoice, not a simplified one (BR-KSA-31).'));
        }

        if ($simplified && $request->boolean('is_summary')) {
            if (! $request->filled('buyer_name')) {
                $errors->add('buyer_name', __('A simplified summary invoice names its buyer (BR-KSA-71).'));
            }

            if (! $request->filled('supply_end_date')) {
                $errors->add('supply_end_date', __('A simplified summary invoice states the period it covers: a supply date and a supply end date (BR-KSA-72).'));
            }
        }

        // BR-KSA-92: a B2G transaction names its contract (BT-12), on the
        // invoice and on the notes against it. Nothing else of the buyer
        // is required beyond any invoice's: a government buyer without a
        // VAT number is named by another identifier, as BR-KSA-81 has it.
        if ($request->boolean('is_government') && ! $request->filled('contract_reference')) {
            $errors->add('contract_reference', __('A government (B2G) invoice names the contract it falls under (BR-KSA-92).'));
        }

        if ($request->boolean('is_export') && $request->filled('buyer_vat_number')) {
            $errors->add('buyer_vat_number', __('An export invoice names no buyer VAT number (BR-KSA-46). Leave the VAT number empty and identify the buyer abroad by another identifier, such as Other (OTH) with their registration number.'));
        }
    }

    /**
     * A discount larger than the lines makes a negative invoice: 100 less 200
     * was drafted, issued and chained with a total of -115.00, which the
     * authority refuses (BR-KSA-F-04). Checked only once the amounts are
     * well-formed, so bcmath never sees what validation has already refused.
     */
    public function refuseDiscountBeyondLines(Validator $validator, Request $request): void
    {
        $discount = $request->input('discount_amount');
        $errors = $validator->errors();

        if ($discount === null || $errors->has('discount_amount') || $errors->has('lines') || $errors->has('lines.*')) {
            return;
        }

        $lines = '0';

        // Each line after its own discount and charges.
        foreach ((array) $request->input('lines', []) as $line) {
            $lines = bcadd($lines, ChargeRules::lineTotal($line), 6);
        }

        if (bccomp((string) $discount, $lines, 6) > 0) {
            $errors->add('discount_amount', __('The discount cannot be larger than the invoice lines (BR-KSA-F-04).'));
        }
    }
}
