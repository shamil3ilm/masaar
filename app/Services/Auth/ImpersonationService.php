<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\Core\ActivityLog;
use App\Models\User;
use App\Services\Core\ActivityLogService;
use Illuminate\Support\Str;
use InvalidArgumentException;

class ImpersonationService
{
    private const IMPERSONATION_TTL_MINUTES = 60;

    public function __construct(
        private ActivityLogService $activityLogService
    ) {}

    public function start(User $admin, User $target, string $reason): array
    {
        if (trim($reason) === '') {
            throw new InvalidArgumentException('Reason is required.');
        }
        if (mb_strlen($reason) > 500) {
            throw new InvalidArgumentException('Reason must not exceed 500 characters.');
        }

        try {
            $payload = auth('api')->payload();
            if ($payload && $payload->get('is_impersonating')) {
                throw new InvalidArgumentException('You cannot impersonate while already in an impersonation session.');
            }
        } catch (InvalidArgumentException $e) {
            throw $e;
        } catch (\Throwable) {
            // No valid payload — not in an impersonation session, continue
        }

        if ($target->is_super_admin) {
            throw new InvalidArgumentException('Super-admin accounts cannot be impersonated.');
        }

        if (!$admin->is_super_admin && !$admin->hasPermission('impersonate_users')) {
            throw new InvalidArgumentException('You do not have permission to impersonate users.');
        }

        if (!$admin->is_super_admin && $admin->organization_id !== $target->organization_id) {
            throw new InvalidArgumentException('You can only impersonate users within your organization.');
        }

        $sessionId = Str::uuid()->toString();

        $token = auth('api')
            ->setTTL(self::IMPERSONATION_TTL_MINUTES)
            ->claims([
                'impersonated_by_id'       => $admin->id,
                'impersonation_session_id' => $sessionId,
                'is_impersonating'         => true,
            ])
            ->login($target);

        $this->activityLogService->log([
            'user_id'                  => $target->id,
            'organization_id'          => $target->organization_id,
            'action'                   => ActivityLog::ACTION_IMPERSONATION_STARTED,
            'entity_type'              => 'User',
            'entity_id'                => $target->id,
            'entity_name'              => $target->name,
            'description'              => "Admin {$admin->name} started impersonating {$target->name}",
            'metadata'                 => [
                'reason'                   => $reason,
                'admin_id'                 => $admin->id,
                'admin_name'               => $admin->name,
                'impersonation_session_id' => $sessionId,
            ],
            'impersonated_by_id'       => $admin->id,
            'impersonation_session_id' => $sessionId,
            'severity'                 => ActivityLog::SEVERITY_WARNING,
            'module'                   => 'core',
        ]);

        return [
            'token'                    => $token,
            'expires_at'               => now()->addMinutes(self::IMPERSONATION_TTL_MINUTES)->toIso8601String(),
            'impersonation_session_id' => $sessionId,
            'target_user'              => $target,
        ];
    }

    public function end(User $target, int $impersonatedById, string $sessionId): void
    {
        $this->activityLogService->log([
            'user_id'                  => $target->id,
            'organization_id'          => $target->organization_id,
            'action'                   => ActivityLog::ACTION_IMPERSONATION_ENDED,
            'entity_type'              => 'User',
            'entity_id'                => $target->id,
            'entity_name'              => $target->name,
            'description'              => "Impersonation session ended for {$target->name}",
            'metadata'                 => [
                'admin_id'                 => $impersonatedById,
                'impersonation_session_id' => $sessionId,
            ],
            'impersonated_by_id'       => $impersonatedById,
            'impersonation_session_id' => $sessionId,
            'severity'                 => ActivityLog::SEVERITY_WARNING,
            'module'                   => 'core',
        ]);

        auth('api')->invalidate(true);
    }
}