<?php

declare(strict_types=1);

namespace App\Models\Core;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImportTemplate extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id',
        'name',
        'entity_type',
        'column_mapping',
        'options',
        'is_default',
    ];

    protected $casts = [
        'column_mapping' => 'array',
        'options' => 'array',
        'is_default' => 'boolean',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Set this template as default for its entity type.
     */
    public function setAsDefault(): void
    {
        // Remove default from other templates of same type
        static::where('organization_id', $this->organization_id)
            ->where('entity_type', $this->entity_type)
            ->where('id', '!=', $this->id)
            ->update(['is_default' => false]);

        $this->update(['is_default' => true]);
    }
}
