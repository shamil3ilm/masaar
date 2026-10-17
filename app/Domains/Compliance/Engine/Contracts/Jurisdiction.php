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
 * class of its own as that port grows. Later phases add ports here - rules,
 * presentation, reports - rather than methods to an existing one.
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

    /** Whether this deployment issues invoices in the country. */
    public function enabled(): bool;

    /** The country's steps inside IssuingLifecycle. */
    public function issuingStep(): IssuingJurisdiction;

    /** Issuing an invoice and handing it to the authority. */
    public function transmission(): Transmission;

    /** What a person is told once an invoice is issued. */
    public function notices(): IssueNotices;
}
