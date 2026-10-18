<?php

declare(strict_types=1);

namespace App\Domains\Platform\Approvals;

use App\Domains\Auth\Enums\AdminPermission;
use App\Domains\Auth\Models\User;
use DomainException;

/**
 * A family of admin actions an approval policy can hold back: what each is
 * called, the permission it takes, what it would change, and doing it.
 *
 * execute() is what the console does when no policy applies and what an
 * approval does when one did, so an approved request runs exactly the code
 * the direct action runs, with the payload the requester sent.
 */
interface ApprovableActions
{
    /**
     * @return list<string> the action keys this family answers for
     */
    public function keys(): array;

    public function label(string $action): string;

    /** The permission the requester needs, checked again when it runs. */
    public function permission(string $action): AdminPermission;

    /**
     * What the action would change, read now: the request shows it. An
     * action that could never run for this requester - on their own access,
     * say - is refused here, before anyone is asked to approve it.
     *
     * @param  array<string, mixed>  $payload
     *
     * @throws DomainException when the action cannot be asked for at all
     */
    public function preview(string $action, array $payload, User $requester): Preview;

    /**
     * Do it, as the requester.
     *
     * @param  array<string, mixed>  $payload
     *
     * @throws DomainException when it is refused - by then the state may have moved on
     */
    public function execute(string $action, array $payload, User $requester): ActionResult;
}
