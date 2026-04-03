<?php

declare(strict_types=1);

namespace App\Domains\Organization\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrganizationGroup extends Model
{
    use HasUuids;

    protected $fillable = ['name', 'status', 'notes'];

    public function organizations(): HasMany
    {
        return $this->hasMany(Organization::class, 'group_id');
    }
}
