<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Auth\ImpersonationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ImpersonationController extends Controller
{
    public function __construct(
        private readonly ImpersonationService $impersonationService,
    ) {}

    /**
     * Start an impersonation session.
     * POST /api/v1/auth/impersonate/{user}
     */
    public function start(Request $request, User $user): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ]);

        $admin = $request->user();

        try {
            $result = $this->impersonationService->start($admin, $user, $validated['reason']);
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 'IMPERSONATION_FAILED', 403);
        }

        return $this->success([
            'token'                    => $result['token'],
            'expires_at'               => $result['expires_at'],
            'impersonation_session_id' => $result['impersonation_session_id'],
            'target_user'              => [
                'id'    => $result['target_user']->id,
                'name'  => $result['target_user']->name,
                'email' => $result['target_user']->email,
            ],
        ], 'Impersonation session started.');
    }

    /**
     * End an impersonation session.
     * POST /api/v1/auth/impersonate/end
     */
    public function end(Request $request): JsonResponse
    {
        $adminId   = $request->attributes->get('impersonated_by_id');
        $sessionId = $request->attributes->get('impersonation_session_id');

        if (! $adminId || ! $sessionId) {
            return $this->error('No active impersonation session found.', 'NOT_IMPERSONATING', 400);
        }

        $targetUser = $request->user();

        $this->impersonationService->end($targetUser, (int) $adminId, (string) $sessionId);

        return $this->success(null, 'Impersonation session ended.');
    }
}
