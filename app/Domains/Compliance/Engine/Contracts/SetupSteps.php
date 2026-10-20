<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Engine\Contracts;

use App\Domains\Organization\Models\Organization;

/**
 * The country's own steps in the setup checklist a new organization works
 * through before it invoices.
 *
 * SetupChecklist puts them around the steps every country shares, in this
 * order: the seller's details, approval, what the country registers with its
 * authority, numbering, the catalogue, and the first invoice.
 *
 * A step is an array: key (its stable name), done, required, route (where a
 * person starts it, or null), title and detail (what the dashboard says), and
 * member (whether a member who is not an administrator may start it).
 */
interface SetupSteps
{
    /**
     * The seller's details the country's documents need.
     *
     * @return array{key: string, done: bool, required: bool, route: string|null, title: string, detail: string, member: bool}
     */
    public function company(Organization $organization): array;

    /**
     * What the organization registers with the authority once approved;
     * none where nothing is registered. A step a platform admin can also set
     * names the console route for it (admin_route, taking the organization's id).
     *
     * @return list<array{key: string, done: bool, required: bool, route: string|null, admin_route?: string, title: string, detail: string, member: bool}>
     */
    public function onboarding(Organization $organization): array;

    /**
     * Its first invoice: done once one has been issued as the country issues it.
     *
     * @return array{key: string, done: bool, required: bool, route: string|null, title: string, detail: string, member: bool}
     */
    public function firstInvoice(Organization $organization): array;
}
