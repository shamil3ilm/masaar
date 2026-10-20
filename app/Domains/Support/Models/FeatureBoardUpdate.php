<?php

declare(strict_types=1);

namespace App\Domains\Support\Models;

use App\Domains\Organization\Concerns\RecordsActors;
use App\Domains\Support\Enums\RequestStage;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A board item moved on, or given a public note, as every customer reads
 * it. Written once and never changed; who wrote it is the admin acting.
 *
 * @property RequestStage|null $stage
 * @property string|null $note
 * @property string|null $note_ar
 */
class FeatureBoardUpdate extends Model
{
    use HasUuids;
    use RecordsActors;

    public const UPDATED_AT = null;

    protected $fillable = ['board_item_id', 'stage', 'note', 'note_ar'];

    /**
     * @return array<string, string>
     */
    public function attributionColumns(): array
    {
        return ['created_by' => 'created_by'];
    }

    protected function casts(): array
    {
        return ['stage' => RequestStage::class];
    }

    /** The note in the reader's language. */
    public function localNote(): ?string
    {
        return app()->getLocale() === 'ar' && filled($this->note_ar) ? $this->note_ar : $this->note;
    }
}
