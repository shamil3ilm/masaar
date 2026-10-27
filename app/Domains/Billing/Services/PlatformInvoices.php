<?php

declare(strict_types=1);

namespace App\Domains\Billing\Services;

use App\Domains\Compliance\Engine\DTOs\IssueOptions;
use App\Domains\Compliance\Engine\Services\ComplianceEngine;
use App\Domains\Compliance\Engine\Services\JurisdictionResolver;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Invoice\Services\InvoiceDrafter;
use App\Domains\Organization\Models\Organization;
use App\Domains\Organization\Services\LanguageGrace;
use App\Domains\Organization\Services\TenantResolver;
use DomainException;

/**
 * The platform as a seller: its documents issued from the billing
 * organization - the operator's own company, onboarded with its tax
 * authority like any customer - through the engine and its country's
 * module, under the same rules any seller there issues by.
 *
 * Plan payments (Billing), receivables (Receivables) and refunds' credit
 * notes (Refunds) all issue here, so the platform's invoices cannot follow
 * different rules depending on which screen made them.
 */
class PlatformInvoices
{
    public function __construct(
        private readonly InvoiceDrafter $drafter,
        private readonly ComplianceEngine $engine,
        private readonly TenantResolver $tenant,
        private readonly JurisdictionResolver $jurisdictions,
        private readonly LanguageGrace $grace,
    ) {}

    /**
     * The billing organization, when one is set.
     */
    public function seller(): ?Organization
    {
        $id = config('billing.organization_id');

        return is_string($id) && $id !== '' ? Organization::query()->find($id) : null;
    }

    /**
     * The billing organization, ready to issue.
     *
     * @throws DomainException saying what is missing
     */
    public function readySeller(): Organization
    {
        $seller = $this->seller()
            ?? throw new DomainException('No billing organization is set (BILLING_ORGANIZATION_ID), so no invoice was issued.');

        if (! $seller->zatca_onboarded) {
            throw new DomainException('The billing organization is not connected to its tax authority yet, so no invoice was issued.');
        }

        return $seller;
    }

    /**
     * The currency the platform's VAT is owed in: its billing organization's
     * country's (Jurisdiction::currency), or the default country's while no
     * billing organization is set.
     */
    public function taxCurrency(): string
    {
        return $this->jurisdictions->forRules($this->seller())->currency();
    }

    /**
     * The country the platform is taxed in: its billing organization's, or
     * the default country's while none is set. A supplier resident anywhere
     * else is non-resident, and its services are reverse charge.
     */
    public function taxCountry(): string
    {
        return $this->jurisdictions->forRules($this->seller())->code();
    }

    /** The standard VAT rate there, as a percentage ("15"). */
    public function standardRate(): string
    {
        return $this->jurisdictions->forRules($this->seller())->invoiceRules()->standardRate();
    }

    /**
     * How the seller's country identifies this buyer, and what it needs of
     * the buyer's details: the platform drafts directly, past the request
     * rules that check this for invoices entered by hand.
     *
     * @return array<string, mixed>
     *
     * @throws DomainException naming what the buyer lacks
     */
    public function buyerIdentity(Organization $seller, Organization $buyer): array
    {
        return $this->jurisdictions->forRules($seller)->invoiceRules()->billedBuyer($buyer);
    }

    /**
     * Draft and issue a document from the billing organization.
     *
     * The platform is a seller like any other: where its country requires
     * Arabic on the invoice (ZATCA's E-Invoicing Detailed Guidelines p60,
     * p63), its own name, the buyer's and every text are checked before
     * anything is drafted - unless an admin gave the billing organization
     * a grace period while those names are filled in.
     *
     * @param  array<string, mixed>  $draft  as InvoiceDrafter takes it
     * @param  array<string, mixed>  $countryFields  as InvoiceDrafter takes them
     *
     * @throws DomainException when it could not be issued, saying why
     */
    public function issue(array $draft, Organization $seller, array $countryFields = []): Invoice
    {
        $missing = $this->jurisdictions->forRules($seller)->languageRules()->missingInDraft($draft, $seller);

        if ($missing !== [] && ! $this->grace->active($seller)) {
            throw new DomainException('The invoice lacks the Arabic its country requires ('.implode(', ', array_keys($missing)).'), so no invoice was issued. Add the Arabic names on the company pages, or give the billing organization a grace period.');
        }

        return $this->tenant->runAs((string) $seller->id, function () use ($draft, $seller, $countryFields): Invoice {
            $invoice = $this->drafter->draft($draft, (string) $seller->id, null, $countryFields);

            $outcome = $this->engine->issue($invoice, $seller, new IssueOptions(file: true));

            if (! $outcome->issued) {
                throw new DomainException($outcome->errors[0] ?? 'The invoice could not be issued.');
            }

            return $invoice->fresh() ?? $invoice;
        });
    }

    /**
     * A credit note the billing organization issued against one of its
     * invoices, for this total, since a moment, that none of $claimed is:
     * the note a refund issued before a crash kept it from saying so.
     *
     * @param  list<string>  $claimed  notes already recorded against refunds
     */
    public function issuedNoteFor(string $invoiceId, string $total, \DateTimeInterface $since, array $claimed): ?Invoice
    {
        $original = $this->find($invoiceId);

        return $original === null ? null : Invoice::withoutTenantScope(fn () => Invoice::query()
            ->where('org_id', $original->org_id)
            ->where('document_type', 'credit_note')
            ->where('billing_ref', $original->invoice_number)
            ->where('status', '!=', 'draft')
            ->where('status', '!=', 'rejected')
            ->where('total', $total)
            ->where('created_at', '>=', $since)
            ->whereNotIn('id', $claimed)
            ->orderBy('created_at')
            ->first());
    }

    /**
     * One of the billing organization's own documents, with its lines,
     * whoever is the tenant of the moment.
     */
    public function find(string $invoiceId): ?Invoice
    {
        $seller = $this->seller();

        return $seller === null ? null : Invoice::withoutTenantScope(fn () => Invoice::query()
            ->with('lines')
            ->where('org_id', $seller->id)
            ->find($invoiceId));
    }
}
