<?php

declare(strict_types=1);

namespace App\Domains\Platform\Http\Concerns;

use App\Domains\Platform\Approvals\ActionResult;
use App\Domains\Platform\Approvals\Approvals;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * An admin action that a policy may hold back for approval: done now and
 * answered by the controller as it always was, or sent for approval and
 * answered with the request.
 *
 * The reason comes from the form's approval_reason field, which the
 * admin.partials.approval-reason partial adds when the action's policy is
 * on - or from the action's own reason, for one that already asks for it.
 */
trait HoldsForApproval
{
    /**
     * @param  array<string, mixed>  $payload
     * @param  Closure(ActionResult): RedirectResponse  $done
     */
    protected function holdOrRun(Approvals $approvals, Request $request, string $action, array $payload, Closure $done, ?string $reason = null): RedirectResponse
    {
        $given = trim((string) $request->input('approval_reason'));
        $outcome = $approvals->submit($action, $payload, $request->user(), $given !== '' ? $given : $reason);

        if ($outcome->waits()) {
            return redirect()->route('admin.approvals.show', $outcome->request->id)
                ->with('success', __('Sent for approval. Nothing changes until it is approved.'));
        }

        return $done($outcome->result);
    }
}
