<?php

declare(strict_types=1);

namespace App\Domains\Billing\Http\Controllers;

use App\Domains\Billing\Services\Billing;
use App\Domains\Billing\Settings\FinanceSettings;
use App\Domains\Platform\Approvals\ActionResult;
use App\Domains\Platform\Approvals\Actions\AccountFreezeActions;
use App\Domains\Platform\Approvals\Approvals;
use App\Domains\Platform\Http\Concerns\HoldsForApproval;
use App\Domains\Platform\Http\Middleware\AuditAdminAction;
use App\Http\Controllers\Controller;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * The overdue-payment freeze in the console: the platform's stages and its
 * switch (Finance › Settings), one customer's own stages or exemption, and
 * freezing or unfreezing one account by hand - each with a reason, and
 * held for approval where a policy says so.
 */
class AdminFreezeController extends Controller
{
    use HoldsForApproval;

    public function __construct(
        private readonly FinanceSettings $settings,
        private readonly Billing $billing,
        private readonly Approvals $approvals,
    ) {}

    /** The switch and the platform's stages. */
    public function policy(Request $request): RedirectResponse
    {
        $request->attributes->set(AuditAdminAction::TARGET, ['Customization', null]);
        $data = $request->validate([
            'reminder_days' => ['required', 'integer'],
            'grace_days' => ['required', 'integer'],
            'restricted_days' => ['required', 'integer'],
        ]);

        $this->settings->setFreeze($request->boolean('enabled'), $data, (string) Auth::id());

        return redirect()->route('admin.finance.settings')->with('success', __('Overdue-payment stages saved.'));
    }

    /** One customer's own stages, or its exemption; empty follows the platform. */
    public function customerPolicy(Request $request, string $id): RedirectResponse
    {
        $this->customer($request, $id);
        $data = $request->validate([
            'reminder_days' => ['nullable', 'integer'],
            'grace_days' => ['nullable', 'integer'],
            'restricted_days' => ['nullable', 'integer'],
        ]);

        $this->settings->setFreezePolicy([...$data, 'exempt' => $request->boolean('exempt') ?: null], (string) Auth::id(), $id);

        return redirect()->route('admin.billing.show', $id)->with('success', __('The customer\'s overdue-payment stages are saved.'));
    }

    public function freeze(Request $request, string $id): RedirectResponse
    {
        $this->customer($request, $id);
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        return $this->act($request, AccountFreezeActions::FREEZE, ['organization_id' => $id, 'reason' => $data['reason']], $id, $data['reason']);
    }

    public function unfreeze(Request $request, string $id): RedirectResponse
    {
        $this->customer($request, $id);
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
            'until' => ['required', 'date', 'after_or_equal:today'],
        ]);

        return $this->act($request, AccountFreezeActions::UNFREEZE, ['organization_id' => $id, 'reason' => $data['reason'], 'until' => $data['until']], $id, $data['reason']);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function act(Request $request, string $action, array $payload, string $id, string $reason): RedirectResponse
    {
        try {
            return $this->holdOrRun($this->approvals, $request, $action, $payload,
                fn (ActionResult $done): RedirectResponse => redirect()->route('admin.billing.show', $id)->with('success', $done->message), $reason);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    private function customer(Request $request, string $id): void
    {
        $request->attributes->set(AuditAdminAction::TARGET, ['Organization', $id]);

        $this->billing->organization($id) ?? abort(404);
    }
}
