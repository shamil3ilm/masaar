<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Core\ActivityLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ImpersonationAuditController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $sessions = ActivityLog::where('action', ActivityLog::ACTION_IMPERSONATION_STARTED)
            ->with(['user:id,name,email', 'impersonatedBy:id,name,email'])
            ->orderByDesc('created_at')
            ->paginate($request->integer('per_page', 20));

        return $this->paginated($sessions);
    }

    public function show(string $sessionId): JsonResponse
    {
        $actions = ActivityLog::where('impersonation_session_id', $sessionId)
            ->with(['user:id,name,email', 'impersonatedBy:id,name,email'])
            ->orderBy('created_at')
            ->get();

        if ($actions->isEmpty()) {
            return $this->notFound('Impersonation session not found.');
        }

        $start = $actions->firstWhere('action', ActivityLog::ACTION_IMPERSONATION_STARTED);
        $end   = $actions->firstWhere('action', ActivityLog::ACTION_IMPERSONATION_ENDED);

        return $this->success([
            'session_id'       => $sessionId,
            'started_at'       => $start?->created_at,
            'ended_at'         => $end?->created_at,
            'reason'           => $start?->metadata['reason'] ?? null,
            'admin'            => $start?->impersonatedBy,
            'target_user'      => $start?->user,
            'duration_minutes' => ($start && $end)
                ? $start->created_at->diffInMinutes($end->created_at)
                : null,
            'actions'          => $actions
                ->whereNotIn('action', [
                    ActivityLog::ACTION_IMPERSONATION_STARTED,
                    ActivityLog::ACTION_IMPERSONATION_ENDED,
                ])
                ->values(),
        ]);
    }
}