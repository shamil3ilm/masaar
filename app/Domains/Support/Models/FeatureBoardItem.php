<?php

declare(strict_types=1);

namespace App\Domains\Support\Models;

use App\Domains\Organization\Concerns\RecordsActors;
use App\Domains\Support\Enums\RequestStage;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One idea on the feature board, however many companies asked for it:
 * worded by an admin in both languages, with no company's name or details.
 * Read through FeatureBoard only, which keeps who asked to itself.
 *
 * @property int $number
 * @property string $title
 * @property string|null $title_ar
 * @property string $summary
 * @property string|null $summary_ar
 * @property string $category
 * @property RequestStage $stage
 * @property Carbon|null $published_at
 * @property string|null $released_in
 * @property string|null $release_url
 * @property string|null $merged_into_id
 */
class FeatureBoardItem extends Model
{
    use HasUuids;
    use RecordsActors;

    protected $fillable = [
        'number',
        'title',
        'title_ar',
        'summary',
        'summary_ar',
        'category',
        'stage',
        'released_in',
        'release_url',
    ];

    /**
     * @return array<string, string>
     */
    public function attributionColumns(): array
    {
        return ['created_by' => 'created_by', 'updated_by' => 'updated_by'];
    }

    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'stage' => RequestStage::class,
            'published_at' => 'datetime',
        ];
    }

    /** What a customer quotes: F-0042. */
    public function reference(): string
    {
        return 'F-'.str_pad((string) $this->number, 4, '0', STR_PAD_LEFT);
    }

    /** Its title in the reader's language. */
    public function localTitle(): string
    {
        return app()->getLocale() === 'ar' && filled($this->title_ar) ? (string) $this->title_ar : (string) $this->title;
    }

    /** Its summary in the reader's language. */
    public function localSummary(): string
    {
        return app()->getLocale() === 'ar' && filled($this->summary_ar) ? (string) $this->summary_ar : (string) $this->summary;
    }

    public function published(): bool
    {
        return $this->published_at !== null && $this->merged_into_id === null;
    }

    public function requests(): HasMany
    {
        return $this->hasMany(FeatureRequest::class, 'board_item_id');
    }

    public function backers(): HasMany
    {
        return $this->hasMany(FeatureBoardBacker::class, 'board_item_id');
    }

    public function followers(): HasMany
    {
        return $this->hasMany(FeatureBoardFollower::class, 'board_item_id');
    }

    public function updates(): HasMany
    {
        return $this->hasMany(FeatureBoardUpdate::class, 'board_item_id')->orderBy('created_at');
    }

    public function mergedInto(): BelongsTo
    {
        return $this->belongsTo(self::class, 'merged_into_id');
    }
}
