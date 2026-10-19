<?php

declare(strict_types=1);

namespace App\Domains\Platform\Models;

use App\Domains\Organization\Concerns\RecordsActors;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * One home page section as Admin › Website set it. Written and read by
 * WebsiteSections only; a section without a row shows its default.
 *
 * The platform's own, so not tenant-scoped.
 *
 * @property string $id
 * @property string $key
 * @property bool $enabled
 * @property int $position
 * @property array<string, mixed>|null $content
 */
class WebsiteSection extends Model
{
    use HasUuids;
    use RecordsActors;

    protected $fillable = [
        'key',
        'enabled',
        'position',
        'content',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'position' => 'integer',
            'content' => 'array',
        ];
    }
}
