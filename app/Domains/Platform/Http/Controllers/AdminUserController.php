<?php

declare(strict_types=1);

namespace App\Domains\Platform\Http\Controllers;

use App\Domains\Auth\Services\AccountStatus;
use App\Domains\Auth\Services\TwoFactor;
use App\Domains\Platform\Http\Middleware\AuditAdminAction;
use App\Domains\Platform\Services\UserReport;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * Users, for the people who support them.
 *
 * Everything a "why can I not sign in" or "who filed this" question needs,
 * which used to need a database client: memberships, sign-ins and the
 * failures against the account, what admins have done to it, and what the
 * user did.
 */
class AdminUserController extends Controller
{
    private const PER_PAGE = 25;

    private const HISTORY = 25;

    public function __construct(
        private readonly UserReport $users,
        private readonly AccountStatus $accounts,
        private readonly TwoFactor $twoFactor,
    ) {}

    public function index(Request $request): View
    {
        $search = $this->given($request->query('q'));
        $status = $this->given($request->query('status'));

        return view('admin.users', [
            'users' => $this->users->page($search, $status, self::PER_PAGE)->withQueryString(),
            'search' => $search,
            'status' => $status,
        ]);
    }

    public function show(string $userId): View
    {
        $user = $this->users->find($userId) ?? abort(404);

        return view('admin.user-detail', [
            'user' => $user,
            'memberships' => $this->users->memberships($userId),
            'signIns' => $this->users->signIns($userId, self::HISTORY),
            'history' => $this->users->accountHistory($userId, self::HISTORY),
            'activity' => $this->users->activity($userId, self::HISTORY),
            'submissions' => $this->users->submissions($userId, self::HISTORY),
        ]);
    }

    public function suspend(Request $request, string $userId): RedirectResponse
    {
        $request->attributes->set(AuditAdminAction::TARGET, ['User', $userId]);

        $request->validate(['reason' => ['required', 'string', 'max:255']]);

        return $this->outcome(
            $this->accounts->suspend($userId, (string) Auth::id()),
            'Account suspended. Its sessions and tokens stop working on their next request.',
            $userId,
        );
    }

    public function reactivate(Request $request, string $userId): RedirectResponse
    {
        $request->attributes->set(AuditAdminAction::TARGET, ['User', $userId]);

        return $this->outcome($this->accounts->reactivate($userId), 'Account reactivated.', $userId);
    }

    /**
     * Let an admin who has lost their phone enrol again at their next sign-in.
     */
    public function resetTwoFactor(Request $request, string $userId): RedirectResponse
    {
        $request->attributes->set(AuditAdminAction::TARGET, ['User', $userId]);

        return $this->outcome(
            $this->twoFactor->resetFor($userId, (string) Auth::id()),
            'Two-factor reset. They set it up again the next time they sign in.',
            $userId,
        );
    }

    private function outcome(?string $refusal, string $success, string $userId): RedirectResponse
    {
        $redirect = redirect()->route('admin.users.show', $userId);

        return $refusal === null ? $redirect->with('success', $success) : $redirect->with('error', $refusal);
    }

    private function given(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
