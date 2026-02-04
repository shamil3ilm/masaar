<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\Core\Activity;
use App\Models\Core\Comment;
use App\Services\Core\ActivityService;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Collection;

/**
 * Trait for models that have an activity timeline.
 *
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Activity> $activities
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Comment> $comments
 */
trait HasActivities
{
    /**
     * Get all activities for this model.
     */
    public function activities(): MorphMany
    {
        return $this->morphMany(Activity::class, 'subject');
    }

    /**
     * Get all comments for this model.
     */
    public function comments(): MorphMany
    {
        return $this->morphMany(Comment::class, 'commentable');
    }

    /**
     * Get top-level comments (not replies).
     */
    public function topLevelComments(): MorphMany
    {
        return $this->comments()->whereNull('parent_id');
    }

    /**
     * Get internal comments only.
     */
    public function internalComments(): MorphMany
    {
        return $this->comments()->where('is_internal', true);
    }

    /**
     * Get public comments only.
     */
    public function publicComments(): MorphMany
    {
        return $this->comments()->where('is_internal', false);
    }

    /**
     * Get the full timeline (activities + comments).
     */
    public function getTimeline(int $limit = 50): Collection
    {
        return app(ActivityService::class)->getTimeline($this, $limit);
    }

    /**
     * Log an activity for this model.
     */
    public function logActivity(string $event, ?string $description = null, ?array $properties = null): Activity
    {
        return app(ActivityService::class)->log(
            $this,
            $event,
            $description,
            $properties
        );
    }

    /**
     * Log a status change.
     */
    public function logStatusChange(string $oldStatus, string $newStatus, ?string $description = null): Activity
    {
        return app(ActivityService::class)->logStatusChanged(
            $this,
            $oldStatus,
            $newStatus,
            $description
        );
    }

    /**
     * Add a comment to this model.
     */
    public function addComment(string $content, bool $isInternal = false): Comment
    {
        return app(ActivityService::class)->addComment($this, $content, $isInternal);
    }

    /**
     * Add a reply to a comment.
     */
    public function addReply(Comment $parentComment, string $content, bool $isInternal = false): Comment
    {
        return app(ActivityService::class)->addComment($this, $content, $isInternal, $parentComment->id);
    }

    /**
     * Check if model has activities.
     */
    public function hasActivities(): bool
    {
        return $this->activities()->exists();
    }

    /**
     * Check if model has comments.
     */
    public function hasComments(): bool
    {
        return $this->comments()->exists();
    }

    /**
     * Get the latest activity.
     */
    public function getLatestActivity(): ?Activity
    {
        return $this->activities()->latest()->first();
    }

    /**
     * Get activities for a specific event.
     */
    public function getActivitiesForEvent(string $event): Collection
    {
        return $this->activities()->where('event', $event)->get();
    }

    /**
     * Auto-log activities on model events.
     */
    protected static function bootHasActivities(): void
    {
        static::created(function ($model) {
            if ($model->shouldLogActivity('created')) {
                app(ActivityService::class)->logCreated($model);
            }
        });

        static::updated(function ($model) {
            if ($model->shouldLogActivity('updated') && $model->isDirty()) {
                app(ActivityService::class)->logUpdated($model, $model->getDirty());
            }
        });

        static::deleted(function ($model) {
            if ($model->shouldLogActivity('deleted')) {
                app(ActivityService::class)->logDeleted($model);
            }
        });
    }

    /**
     * Determine if activity should be logged for event.
     * Override in model to customize.
     */
    protected function shouldLogActivity(string $event): bool
    {
        // By default, log all events
        // Override in your model to customize
        return property_exists($this, 'logActivities')
            ? in_array($event, $this->logActivities)
            : true;
    }

    /**
     * Get fields that should be excluded from activity logging.
     */
    protected function getActivityExcludedFields(): array
    {
        return property_exists($this, 'activityExcludedFields')
            ? $this->activityExcludedFields
            : ['updated_at', 'created_at', 'remember_token'];
    }
}
