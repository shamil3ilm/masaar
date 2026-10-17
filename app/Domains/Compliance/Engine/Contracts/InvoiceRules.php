<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Engine\Contracts;

use App\Domains\Organization\Models\Organization;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Validation\Validator;

/**
 * A country's rules for drafting an invoice: what a request has to be, what
 * is filled in when the caller leaves it out, and how the validated request
 * becomes InvoiceDrafter's draft and the columns only this country's
 * invoices carry.
 *
 * The API, the ERP pipeline and the portal ask the organization's country
 * for these, so one invoice is checked by one set of rules whichever way it
 * arrives, and none of them names a country.
 */
interface InvoiceRules
{
    /**
     * What the request is given before it is validated: defaults for what the
     * caller left out, without contradicting what it said.
     *
     * @return array<string, mixed> merged into the request's input
     */
    public function defaults(Request $request): array;

    /**
     * @return array<string, mixed>
     */
    public function rules(Request $request): array;

    /**
     * @return array<string, string>
     */
    public function messages(): array;

    /** The checks across fields, run once the rules have. */
    public function after(Validator $validator, Request $request): void;

    /**
     * The draft as InvoiceDrafter takes it, from the validated request.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    public function draft(array $validated): array;

    /**
     * The header columns only this country's invoices carry, for
     * InvoiceDrafter's $countryFields. Empty for a country with none.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    public function countryFields(array $validated): array;

    /** The rate a line is taxed at when it is standard-rated and states none. */
    public function standardRate(): string;

    /**
     * The tax categories a line can carry.
     *
     * @return list<string>
     */
    public function taxCategories(): array;

    /**
     * The codes a line names its exemption or zero rating by.
     *
     * @return list<string>
     */
    public function exemptionCodes(): array;

    /**
     * The kinds of document the country issues, as DocumentType's values.
     *
     * @return list<string>
     */
    public function documentTypes(): array;

    /**
     * The invoice types the country has, as InvoiceType's values.
     *
     * @return list<string>
     */
    public function invoiceTypes(): array;

    /** The Blade view of the portal's new-invoice form. */
    public function portalView(): string;

    /** The name of the route that form posts to. */
    public function portalRoute(): string;

    /**
     * A customer's buyer details beyond those every country keeps, or rules
     * replacing them, by field.
     *
     * @return array<string, list<mixed>>
     */
    public function buyerRules(Request $request): array;

    /**
     * @return array<string, string>
     */
    public function buyerMessages(): array;

    /** The Blade view of the customer form's extra fields, given $customer; null when there are none. */
    public function buyerView(): ?string;

    /**
     * How a seller in this country identifies an organization as its buyer
     * on an invoice drafted straight from the organization's own details,
     * past the request rules - the platform's invoice for a plan - and the
     * check that those details are enough for this country's invoice.
     *
     * @return array{buyer_vat_number: string|null, buyer_id: string|null, buyer_id_scheme: string|null}
     *
     * @throws DomainException naming what the buyer is missing
     */
    public function billedBuyer(Organization $buyer): array;
}
