<?php

declare(strict_types=1);

namespace App\Domains\Platform\Http\Controllers;

use App\Domains\Auth\Enums\PlatformRole;
use App\Domains\Auth\Services\PlatformAdmins;
use App\Domains\Platform\Http\Middleware\AuditAdminAction;
use App\Http\Controllers\Controller;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * The platform's admins and their roles. Owners only (admins.manage).
 */
class AdminAdminsController extends Controller
{
    public const LINK = 'enrolment_link';

    public function __construct(
        private readonly PlatformAdmins $admins,
    ) {}

    public function index(): View
    {
        return view('admin.admins', [
            'admins' => $this->admins->all(),
            'roles' => PlatformRole::cases(),
        ]);
    }

    public function grant(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'role' => ['required', Rule::enum(PlatformRole::class)],
        ]);

        try {
            $link = $this->admins->grant($data['email'], PlatformRole::from($data['role']), $request->user());
        } catch (DomainException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return back()
            ->with('success', $data['email'].' is now an admin.'.($link ? ' Send them the enrolment link below; they set up two-factor before they can sign in.' : ''))
            ->with(self::LINK, $link);
    }

    public function role(Request $request, string $userId): RedirectResponse
    {
        $request->attributes->set(AuditAdminAction::TARGET, ['User', $userId]);

        $data = $request->validate(['role' => ['required', Rule::enum(PlatformRole::class)]]);

        try {
            $this->admins->changeRole($userId, PlatformRole::from($data['role']), $request->user());
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Role changed.');
    }

    public function revoke(Request $request, string $userId): RedirectResponse
    {
        $request->attributes->set(AuditAdminAction::TARGET, ['User', $userId]);

        try {
            $this->admins->revoke($userId, $request->user());
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Admin access removed.');
    }
}
