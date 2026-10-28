<?php

declare(strict_types=1);

namespace App\Domains\Platform\Approvals;

use App\Domains\Auth\Models\User;
use App\Domains\Organization\Models\Organization;
use DomainException;

/**
 * An action inside one organization that its own policy can hold for a
 * second person (OrganizationApprovals): what it is called, who may approve
 * it, what it would do, and doing it.
 *
 * execute() is what happens when nothing holds it and what an approval
 * runs, so an approved action runs exactly the code a direct one does - and
 * runs every check again, since the state may have moved on.
 */
interface OrganizationAction
{
    public function label(): string;

    /**
     * The organization roles that may approve it.
     *
     * @return list<string>
     */
    public function approverRoles(): array;

    /**
     * What it would do, read now: the request shows it.
     *
     * @param  array<string, mixed>  $payload
     * @param  User|null  $requester  null for an integration's request (OrganizationApprovals::submitFromIntegration)
     *
     * @throws DomainException when it cannot be asked for at all
     */
    public function preview(array $payload, Organization $organization, ?User $requester): Preview;

    /**
     * Do it, as the requester.
     *
     * @param  array<string, mixed>  $payload
     *
     * @throws DomainException when it is refused - by then the state may have moved on
     */
    public function execute(array $payload, Organization $organization, User $requester): ActionResult;
}
