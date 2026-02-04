<?php

declare(strict_types=1);

namespace App\Services\Core;

use App\Models\Core\Activity;
use App\Models\Core\Comment;
use App\Models\Core\Mention;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class ActivityService
{
    /**
     * Log an activity for a model.
     */
    public function log(
        Model $subject,
        string $event,
        ?string $description = null,
        ?array $properties = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?Model $causer = null
    ): Activity {
        $user = auth()->user();

        return Activity::create([
            'organization_id' => $subject->organization_id ?? $user?->organization_id,
            'user_id' => $user?->id,
            'branch_id' => $user?->current_branch_id ?? null,
            'subject_type' => get_class($subject),
            'subject_id' => $subject->id,
            'causer_type' => $causer ? get_class($causer) : ($user ? get_class($user) : null),
            'causer_id' => $causer?->id ?? $user?->id,
            'event' => $event,
            'description' => $description,
            'properties' => $properties,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'source' => $this->detectSource(),
        ]);
    }

    /**
     * Log a model creation.
     */
    public function logCreated(Model $subject, ?string $description = null): Activity
    {
        return $this->log(
            $subject,
            Activity::EVENT_CREATED,
            $description,
            ['attributes' => $subject->getAttributes()]
        );
    }

    /**
     * Log a model update with changes.
     */
    public function logUpdated(Model $subject, array $changes, ?string $description = null): Activity
    {
        $oldValues = [];
        $newValues = [];

        foreach ($changes as $field => $newValue) {
            $oldValues[$field] = $subject->getOriginal($field);
            $newValues[$field] = $newValue;
        }

        return $this->log(
            $subject,
            Activity::EVENT_UPDATED,
            $description,
            null,
            $oldValues,
            $newValues
        );
    }

    /**
     * Log a model deletion.
     */
    public function logDeleted(Model $subject, ?string $description = null): Activity
    {
        return $this->log(
            $subject,
            Activity::EVENT_DELETED,
            $description,
            ['attributes' => $subject->getAttributes()]
        );
    }

    /**
     * Log a status change.
     */
    public function logStatusChanged(Model $subject, string $oldStatus, string $newStatus, ?string $description = null): Activity
    {
        return $this->log(
            $subject,
            Activity::EVENT_STATUS_CHANGED,
            $description ?? "Status changed from {$oldStatus} to {$newStatus}",
            ['old_status' => $oldStatus, 'new_status' => $newStatus],
            ['status' => $oldStatus],
            ['status' => $newStatus]
        );
    }

    /**
     * Log a custom event.
     */
    public function logCustom(Model $subject, string $event, string $description, ?array $properties = null): Activity
    {
        return $this->log($subject, $event, $description, $properties);
    }

    /**
     * Get activity timeline for a model.
     */
    public function getTimeline(Model $subject, int $limit = 50): Collection
    {
        $activities = Activity::forSubject($subject)
            ->with('user')
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();

        $comments = Comment::forEntity($subject)
            ->with(['user', 'replies.user', 'mentions'])
            ->topLevel()
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();

        // Merge and sort by created_at
        return collect()
            ->merge($activities->map(fn($a) => ['type' => 'activity', 'data' => $a, 'created_at' => $a->created_at]))
            ->merge($comments->map(fn($c) => ['type' => 'comment', 'data' => $c, 'created_at' => $c->created_at]))
            ->sortByDesc('created_at')
            ->take($limit)
            ->values();
    }

    /**
     * Get recent activities for an organization.
     */
    public function getRecentActivities(int $organizationId, int $limit = 50, ?array $events = null): Collection
    {
        $query = Activity::where('organization_id', $organizationId)
            ->with(['user', 'subject'])
            ->orderBy('created_at', 'desc');

        if ($events) {
            $query->whereIn('event', $events);
        }

        return $query->limit($limit)->get();
    }

    /**
     * Get activities by user.
     */
    public function getByUser(int $userId, int $limit = 50): Collection
    {
        return Activity::byUser($userId)
            ->with('subject')
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();
    }

    /**
     * Add a comment to a model.
     */
    public function addComment(Model $subject, string $content, bool $isInternal = false, ?int $parentId = null): Comment
    {
        $user = auth()->user();

        $comment = Comment::create([
            'organization_id' => $subject->organization_id ?? $user->organization_id,
            'user_id' => $user->id,
            'commentable_type' => get_class($subject),
            'commentable_id' => $subject->id,
            'parent_id' => $parentId,
            'content' => $content,
            'is_internal' => $isInternal,
        ]);

        // Process mentions
        $this->processMentions($comment);

        // Log activity
        $this->log($subject, Activity::EVENT_COMMENTED, null, [
            'comment_id' => $comment->id,
            'is_internal' => $isInternal,
        ]);

        return $comment;
    }

    /**
     * Process @mentions in a comment.
     */
    protected function processMentions(Comment $comment): void
    {
        $usernames = $comment->extractMentions();

        if (empty($usernames)) {
            return;
        }

        $users = User::where('organization_id', $comment->organization_id)
            ->whereIn('name', $usernames)
            ->orWhereIn('email', array_map(fn($u) => $u . '@%', $usernames))
            ->get();

        foreach ($users as $user) {
            if ($user->id !== $comment->user_id) {
                Mention::create([
                    'comment_id' => $comment->id,
                    'user_id' => $user->id,
                ]);

                // Could dispatch notification here
            }
        }
    }

    /**
     * Get unread mentions for a user.
     */
    public function getUnreadMentions(int $userId): Collection
    {
        return Mention::forUser($userId)
            ->unread()
            ->with(['comment.commentable', 'comment.user'])
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /**
     * Mark mentions as read.
     */
    public function markMentionsAsRead(int $userId, ?array $mentionIds = null): int
    {
        $query = Mention::forUser($userId)->unread();

        if ($mentionIds) {
            $query->whereIn('id', $mentionIds);
        }

        return $query->update([
            'is_read' => true,
            'read_at' => now(),
        ]);
    }

    /**
     * Get activity statistics.
     */
    public function getStatistics(int $organizationId, ?string $startDate = null, ?string $endDate = null): array
    {
        $query = Activity::where('organization_id', $organizationId);

        if ($startDate) {
            $query->where('created_at', '>=', $startDate);
        }
        if ($endDate) {
            $query->where('created_at', '<=', $endDate);
        }

        return [
            'total_activities' => (clone $query)->count(),
            'by_event' => (clone $query)
                ->selectRaw('event, count(*) as count')
                ->groupBy('event')
                ->pluck('count', 'event')
                ->toArray(),
            'by_user' => (clone $query)
                ->selectRaw('user_id, count(*) as count')
                ->groupBy('user_id')
                ->with('user:id,name')
                ->limit(10)
                ->get()
                ->mapWithKeys(fn($row) => [$row->user?->name ?? 'Unknown' => $row->count])
                ->toArray(),
            'by_source' => (clone $query)
                ->selectRaw('source, count(*) as count')
                ->groupBy('source')
                ->pluck('count', 'source')
                ->toArray(),
            'by_day' => (clone $query)
                ->selectRaw('DATE(created_at) as date, count(*) as count')
                ->groupBy('date')
                ->orderBy('date')
                ->pluck('count', 'date')
                ->toArray(),
        ];
    }

    /**
     * Detect the source of the activity.
     */
    protected function detectSource(): string
    {
        if (app()->runningInConsole()) {
            return Activity::SOURCE_SYSTEM;
        }

        if (request()->is('api/*')) {
            return Activity::SOURCE_API;
        }

        $userAgent = request()->userAgent() ?? '';
        if (str_contains(strtolower($userAgent), 'mobile')) {
            return Activity::SOURCE_MOBILE;
        }

        return Activity::SOURCE_WEB;
    }

    /**
     * Clean up old activities.
     */
    public function cleanup(int $daysToKeep = 365): int
    {
        return Activity::where('created_at', '<', now()->subDays($daysToKeep))->delete();
    }
}
