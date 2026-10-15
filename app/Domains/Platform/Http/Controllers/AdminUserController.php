<?php

declare(strict_types=1);

namespace App\Domains\Platform\Http\Controllers;

use App\Domains\Auth\Services\AccountStatus;
use App\Domains\Auth\Services\EnrolmentLinks;
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

    public const LINK = 'enrolment_link';

    public function __construct(
        private readonly UserReport $users,
        private readonly AccountStatus $accounts,
        private readonly TwoFactor $twoFactor,
        private readonly EnrolmentLinks $links,
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

        $refusal = $this->twoFactor->resetFor($userId, (string) Auth::id());

        if ($refusal !== null) {
            return $this->outcome($refusal, '', $userId);
        }

        // They cannot enrol again without a link, so the reset comes with one
        // - for a platform admin. Anyone else no longer needs a second factor,
        // and the reset is done either way.
        [$notIssued, $link] = $this->links->issueFor($userId, (string) Auth::id());

        if ($notIssued !== null) {
            return $this->outcome(null, 'Two-factor reset.', $userId);
        }

        return $this->outcome(null, 'Two-factor reset. Send them the enrolment link below; they open it, then sign in.', $userId)
            ->with(self::LINK, $link);
    }

    /**
     * A one-time link for an admin who has not enrolled - promoted, or whose
     * last link lapsed. Issuing one replaces the last.
     */
    public function issueEnrolmentLink(Request $request, string $userId): RedirectResponse
    {
        $request->attributes->set(AuditAdminAction::TARGET, ['User', $userId]);

        return $this->withLink(
            $this->links->issueFor($userId, (string) Auth::id()),
            'Enrolment link issued. Send it to them; they open it, then sign in.',
            $userId,
        );
    }

    /**
     * Shown once, on the next page, and kept nowhere else: only its hash is
     * stored.
     *
     * \param  array{0: string|null, 1: string|null}  $issued
     */
    private function withLink(array $issued, string $success, string $userId): RedirectResponse
    {
        [$refusal, $link] = $issued;

        return $this->outcome($refusal, $success, $userId)->with(self::LINK, $link);
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
