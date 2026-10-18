<?php

declare(strict_types=1);

namespace App\Domains\Platform\Http\Controllers;

use App\Domains\Platform\Approvals\ApprovalCatalogue;
use App\Domains\Platform\Approvals\Approvals;
use App\Domains\Platform\Customization\CustomizationForm;
use App\Domains\Platform\Customization\Customizations;
use App\Domains\Platform\Customization\Level;
use App\Domains\Platform\Customization\Scope;
use App\Domains\Platform\Http\Middleware\AuditAdminAction;
use App\Http\Controllers\Controller;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Admin › Approvals: what waits for this admin's approval, what they asked
 * for, and every decision - and deciding.
 */
class AdminApprovalsController extends Controller
{
    public const LINK = 'enrolment_link';

    private const NOTIFICATIONS = 'notifications.approval_requests';

    public function __construct(
        private readonly Approvals $approvals,
        private readonly ApprovalCatalogue $catalogue,
        private readonly Customizations $customizations,
        private readonly CustomizationForm $form,
    ) {}

    public function index(Request $request): View
    {
        return view('admin.approvals.index', [
            ...$this->approvals->lists($request->user()),
            'catalogue' => $this->catalogue,
            'notifications' => $this->customizations->resolve(self::NOTIFICATIONS, Scope::person((string) $request->user()->id)),
        ]);
    }

    public function show(Request $request, string $requestId): View
    {
        $approval = $this->approvals->find($requestId) ?? abort(404);

        return view('admin.approvals.show', [
            'approval' => $approval,
            'label' => $this->catalogue->label($approval->action),
            'mayDecide' => $this->approvals->mayDecide($approval, $request->user())
                && ! $approval->decisions->contains('user_id', $request->user()->id),
        ]);
    }

    public function approve(Request $request, string $requestId): RedirectResponse
    {
        $request->attributes->set(AuditAdminAction::TARGET, ['ApprovalRequest', $requestId]);
        $note = $request->validate(['note' => ['nullable', 'string', 'max:1000']])['note'] ?? null;

        try {
            $result = $this->approvals->approve($requestId, $request->user(), $note);
        } catch (DomainException $e) {
            return redirect()->route('admin.approvals.show', $requestId)->with('error', $e->getMessage());
        }

        $approval = $this->approvals->find($requestId);

        // Shown once to whoever made it happen, as the direct action shows
        // it: a new licence's secret on the licence, an enrolment link here.
        if (isset($result?->data['api_secret'], $result->data['licence_id'])) {
            return redirect()->route('admin.licences.show', $result->data['licence_id'])
                ->with('success', $result->message)->with('api_secret', $result->data['api_secret']);
        }

        return redirect()->route('admin.approvals.show', $requestId)
            ->with($approval?->status === 'failed' ? 'error' : 'success', match (true) {
                $result !== null => $result->message,
                $approval?->status === 'failed' => __('Approved, but it could not be done: :reason', ['reason' => $approval->outcome]),
                default => __('Approved. It happens once enough admins approve it.'),
            })
            ->with(self::LINK, $result?->data['enrolment_link'] ?? null);
    }

    public function reject(Request $request, string $requestId): RedirectResponse
    {
        $request->attributes->set(AuditAdminAction::TARGET, ['ApprovalRequest', $requestId]);
        $reason = (string) $request->validate(['reason' => ['required', 'string', 'max:1000']])['reason'];

        try {
            $this->approvals->reject($requestId, $request->user(), $reason);
        } catch (DomainException $e) {
            return redirect()->route('admin.approvals.show', $requestId)->with('error', $e->getMessage());
        }

        return redirect()->route('admin.approvals.show', $requestId)->with('success', __('Rejected. Nothing was changed, and the admin who asked has been told why.'));
    }

    public function withdraw(Request $request, string $requestId): RedirectResponse
    {
        $request->attributes->set(AuditAdminAction::TARGET, ['ApprovalRequest', $requestId]);

        try {
            $this->approvals->withdraw($requestId, $request->user());
        } catch (DomainException $e) {
            return redirect()->route('admin.approvals.show', $requestId)->with('error', $e->getMessage());
        }

        return redirect()->route('admin.approvals.show', $requestId)->with('success', __('Withdrawn. Nothing was changed.'));
    }

    /** Whether this admin is emailed about requests waiting for them. */
    public function notifications(Request $request): RedirectResponse
    {
        $message = $this->form->apply($request, self::NOTIFICATIONS, Level::User, Scope::person((string) $request->user()->id), (string) $request->user()->id);

        return redirect()->route('admin.approvals.index')->with('success', $message);
    }
}
