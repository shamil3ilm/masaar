<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Policies;

use App\Domains\Auth\Models\User;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Services\BranchAccess;

/**
 * Who may read an invoice, and who may issue and send it, by the branch it
 * belongs to.
 *
 * InvoiceFinder already leaves out what a person may not see, so a URL to
 * another branch's invoice is a 404 before this is asked; the policy is the
 * same rule said again where an action is taken, and the one place a
 * branch's viewer is told they may read but not issue.
 */
class InvoicePolicy
{
    public function __construct(
        private readonly BranchAccess $access,
    ) {}

    public function view(User $user, Invoice $invoice): bool
    {
        return $this->access->roleAt((string) $user->id, (string) $invoice->org_id, $invoice->branch_id) !== null;
    }

    /** Issue a draft, correct it with a note, or send it to the buyer. */
    public function issue(User $user, Invoice $invoice): bool
    {
        return $this->access->mayWriteAt((string) $user->id, (string) $invoice->org_id, $invoice->branch_id);
    }
}
