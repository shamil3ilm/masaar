<?php

declare(strict_types=1);

namespace App\Domains\Branding\Models;

use App\Domains\Organization\Concerns\RecordsActors;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A stored theme: the platform's when org_id is null, an organization's
 * override otherwise.
 *
 * @property string $id
 * @property string $scope
 * @property string|null $org_id
 * @property array<string, mixed> $definition
 * @property string|null $logo_path
 * @property string|null $logo_type
 * @property string|null $favicon_path
 * @property string|null $favicon_type
 * @property int $version
 * @property string|null $updated_by
 */
class Theme extends Model
{
    use HasUuids;
    use RecordsActors;

    protected $fillable = [
        'scope',
        'org_id',
        'definition',
        'logo_path',
        'logo_type',
        'favicon_path',
        'favicon_type',
        'version',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'definition' => 'array',
            'version' => 'integer',
        ];
    }
}
