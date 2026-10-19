<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Engine\Contracts;

use App\Domains\Invoice\Contracts\IssuingJurisdiction;

/**
 * One country, as the engine sees it: who it is, whether this deployment
 * offers it, and the ports through which shared code reaches its rules.
 *
 * A module implements this in its own folder and registers itself with
 * JurisdictionRegistry from its own service provider. Shared code never asks
 * which country an organization is in; it asks the engine for the module and
 * talks to its ports.
 *
 * Each port is an interface of its own, so a module can answer one with a
 * class of its own as that port grows. A new concern is a new port here
 * rather than methods on an existing one.
 */
interface Jurisdiction
{
    /** ISO 3166-1 alpha-2, as an organization's country holds it. */
    public function code(): string;

    /** The country's name, in English. */
    public function name(): string;

    /** The IANA time zone the country's documents are dated in. */
    public function timezone(): string;

    /** The ISO 4217 code of the country's tax currency. */
    public function currency(): string;

    /** The e-invoicing system the country's documents go through, as an operator calls it. */
    public function filingSystem(): string;

    /**
     * Whether this deployment issues invoices in the country: a platform
     * admin's switch when one is thrown, otherwise the module's configured
     * default. Every module answers it through JurisdictionAvailability.
     */
    public function enabled(): bool;

    /**
     * What an operator checks before switching the country on, label =>
     * value. Read from configuration only - no network, no query - because
     * the admin console asks every module on every render.
     *
     * @return array<string, string>
     */
    public function readiness(): array;

    /**
     * What stops a production instance serving the country, as sentences
     * for the operator: empty when nothing does. Asked by the readiness
     * probe in production, while the country is on, so it may look past
     * configuration - and should keep what it finds briefly.
     *
     * @return list<string>
     */
    public function readinessProblems(): array;

    /** The country's steps inside IssuingLifecycle. */
    public function issuingStep(): IssuingJurisdiction;

    /** Issuing an invoice and handing it to the authority. */
    public function transmission(): Transmission;

    /** What a person is told once an invoice is issued. */
    public function notices(): IssueNotices;

    /** Which of the platform's features exist here, for Access. */
    public function features(): FeatureSupport;

    /** What the seller's details have to be, and where they are kept. */
    public function sellerRules(): SellerRules;

    /** What an invoice draft has to be, and the columns only this country's invoices carry. */
    public function invoiceRules(): InvoiceRules;

    /** Which language its invoices have to carry, and what a saved one lacks of it. */
    public function languageRules(): LanguageRules;

    /** How the portal shows the country, and when and how an invoice is handed to the buyer. */
    public function presentation(): Presentation;

    /** The tax report and its CSVs. */
    public function reports(): TaxReports;

    /** The dashboard figures that depend on how the country files. */
    public function dashboard(): Dashboard;

    /** The country's steps in the setup checklist. */
    public function setup(): SetupSteps;

    /** A branch as a device the authority knows, for the branch screens. */
    public function devices(): Devices;
}
