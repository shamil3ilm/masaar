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
        private ImpersonationService $impersonationService
    ) {}

    public function start(Request $request, User $user): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ]);

        return $this->tryAction(
            fn() => $this->impersonationService->start(
                auth()->user(),
                $user,
                $validated['reason']
            ),
            'Impersonation session started.',
            'IMPERSONATION_FAILED',
            400
        );
    }

    public function end(): JsonResponse
    {
        try {
            $payload = auth('api')->payload();
        } catch (\Throwable) {
            return $this->error('No active impersonation session.', 'NOT_IMPERSONATING', 400);
        }

        if (!$payload || !$payload->get('is_impersonating')) {
            return $this->error('No active impersonation session.', 'NOT_IMPERSONATING', 400);
        }

        $this->impersonationService->end(
            auth()->user(),
            (int) $payload->get('impersonated_by_id'),
            (string) $payload->get('impersonation_session_id')
        );

        return $this->success(null, 'Impersonation session ended.');
    }
}