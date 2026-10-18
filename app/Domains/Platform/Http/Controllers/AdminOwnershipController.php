<?php

declare(strict_types=1);

namespace App\Domains\Platform\Http\Controllers;

use App\Domains\Auth\Services\PlatformOwnership;
use App\Domains\Platform\Http\Middleware\AuditAdminAction;
use App\Http\Controllers\Controller;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Handing the platform's ownership on: the owner offers it to one admin,
 * that admin accepts, and only then does the owner step down to the role
 * they chose (PlatformOwnership).
 */
class AdminOwnershipController extends Controller
{
    public function __construct(
        private readonly PlatformOwnership $ownership,
    ) {}

    public function offer(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'user_id' => ['required', 'uuid'],
            'demote_to' => ['required', 'string', 'max:20'],
        ]);
        $request->attributes->set(AuditAdminAction::TARGET, ['User', $data['user_id']]);

        try {
            $this->ownership->offer($request->user(), $data['user_id'], $data['demote_to']);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', __('Ownership is offered. It moves when they accept; until then you are the owner.'));
    }

    public function withdraw(Request $request, string $transferId): RedirectResponse
    {
        $request->attributes->set(AuditAdminAction::TARGET, ['OwnerTransfer', $transferId]);

        try {
            $this->ownership->cancel($transferId, $request->user());
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', __('The offer of ownership is withdrawn.'));
    }

    public function accept(Request $request, string $transferId): RedirectResponse
    {
        $request->attributes->set(AuditAdminAction::TARGET, ['OwnerTransfer', $transferId]);

        try {
            $this->ownership->accept($transferId, $request->user());
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('admin.admins.index')->with('success', __('You are the platform owner now.'));
    }
}
