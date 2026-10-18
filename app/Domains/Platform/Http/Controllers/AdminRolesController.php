<?php

declare(strict_types=1);

namespace App\Domains\Platform\Http\Controllers;

use App\Domains\Auth\Enums\AdminPermission;
use App\Domains\Auth\Services\AdminRoles;
use App\Domains\Platform\Approvals\ActionResult;
use App\Domains\Platform\Approvals\Approvals;
use App\Domains\Platform\Http\Concerns\HoldsForApproval;
use App\Domains\Platform\Http\Middleware\AuditAdminAction;
use App\Http\Controllers\Controller;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Admin roles: the four system roles, and custom ones made from the
 * permission set. Owners only (admins.manage); held for approval like
 * granting access.
 */
class AdminRolesController extends Controller
{
    use HoldsForApproval;

    public function __construct(
        private readonly AdminRoles $roles,
        private readonly Approvals $approvals,
    ) {}

    public function index(): View
    {
        return view('admin.roles', [
            'roles' => $this->roles->all(),
            'holders' => $this->roles->holders(),
            'permissions' => AdminPermission::cases(),
            'maxRank' => AdminRoles::MAX_CUSTOM_RANK,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        return $this->save($request, null);
    }

    public function update(Request $request, string $key): RedirectResponse
    {
        abort_unless($this->roles->exists($key), 404);

        return $this->save($request, $key);
    }

    public function destroy(Request $request, string $key): RedirectResponse
    {
        $request->attributes->set(AuditAdminAction::TARGET, ['AdminRole', null]);

        try {
            return $this->holdOrRun($this->approvals, $request, 'admin_roles.delete', ['key' => $key], fn (ActionResult $done): RedirectResponse => $this->back($done->message));
        } catch (DomainException $e) {
            return redirect()->route('admin.roles.index')->with('error', $e->getMessage());
        }
    }

    private function save(Request $request, ?string $key): RedirectResponse
    {
        $request->attributes->set(AuditAdminAction::TARGET, ['AdminRole', null]);

        try {
            $values = $this->roles->clean($key, $request->only(['name', 'description', 'permissions', 'rank']));

            return $this->holdOrRun($this->approvals, $request, 'admin_roles.save', ['values' => $values], fn (ActionResult $done): RedirectResponse => $this->back($done->message));
        } catch (DomainException $e) {
            return redirect()->route('admin.roles.index')->with('error', $e->getMessage());
        }
    }

    private function back(string $message): RedirectResponse
    {
        return redirect()->route('admin.roles.index')->with('success', $message);
    }
}
