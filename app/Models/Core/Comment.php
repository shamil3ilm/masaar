<?php

declare(strict_types=1);

namespace App\Models\Core;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Comment extends Model
{
    use BelongsToOrganization, SoftDeletes;

    protected $fillable = [
        'organization_id',
        'user_id',
        'commentable_type',
        'commentable_id',
        'parent_id',
        'content',
        'is_internal',
        'is_pinned',
    ];

    protected $casts = [
        'is_internal' => 'boolean',
        'is_pinned' => 'boolean',
    ];

    // Relationships

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function commentable(): MorphTo
    {
        return $this->morphTo();
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Comment::class, 'parent_id');
    }

    public function replies(): HasMany
    {
        return $this->hasMany(Comment::class, 'parent_id');
    }

    public function mentions(): HasMany
    {
        return $this->hasMany(Mention::class);
    }

    // Scopes

    public function scopeTopLevel($query)
    {
        return $query->whereNull('parent_id');
    }

    public function scopeInternal($query)
    {
        return $query->where('is_internal', true);
    }

    public function scopePublic($query)
    {
        return $query->where('is_internal', false);
    }

    public function scopePinned($query)
    {
        return $query->where('is_pinned', true);
    }

    public function scopeForEntity($query, Model $entity)
    {
        return $query->where('commentable_type', get_class($entity))
            ->where('commentable_id', $entity->id);
    }

    // Helpers

    public function isReply(): bool
    {
        return $this->parent_id !== null;
    }

    public function hasReplies(): bool
    {
        return $this->replies()->exists();
    }

    public function isInternal(): bool
    {
        return $this->is_internal;
    }

    public function isPinned(): bool
    {
        return $this->is_pinned;
    }

    public function pin(): self
    {
        $this->update(['is_pinned' => true]);
        return $this;
    }

    public function unpin(): self
    {
        $this->update(['is_pinned' => false]);
        return $this;
    }

    /**
     * Extract @mentions from content.
     */
    public function extractMentions(): array
    {
        preg_match_all('/@(\w+)/', $this->content, $matches);
        return $matches[1] ?? [];
    }

    /**
     * Get content with mentions as HTML links.
     */
    public function getFormattedContent(): string
    {
        return preg_replace(
            '/@(\w+)/',
            '<span class="mention" data-user="$1">@$1</span>',
            e($this->content)
        );
    }
}
