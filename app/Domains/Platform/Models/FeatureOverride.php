<?php

declare(strict_types=1);

namespace App\Domains\Platform\Models;

use App\Domains\Organization\Concerns\RecordsActors;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A platform admin's switch for one feature of one organization: 'off', or
 * 'on' beyond the plan. Read through FeatureOverrides only.
 *
 * Not tenant-scoped: a platform admin, who holds no tenant, writes it, and
 * FeatureOverrides names the organization in every query.
 *
 * @property string $id
 * @property string $org_id
 * @property string $feature
 * @property string $state
 * @property string $reason
 * @property string|null $set_by
 */
class FeatureOverride extends Model
{
    use HasUuids;
    use RecordsActors;

    public const OFF = 'off';

    public const ON = 'on';

    protected $table = 'organization_feature_overrides';

    protected $fillable = [
        'org_id',
        'subject',
        'feature',
        'state',
        'reason',
        'set_by',
    ];

    /**
     * Who set the switch as it stands is who last changed it.
     *
     * @return array<string, string>
     */
    public function attributionColumns(): array
    {
        return ['created_by' => 'created_by', 'updated_by' => 'set_by'];
    }
}
