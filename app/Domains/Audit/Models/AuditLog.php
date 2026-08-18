<?php

namespace App\Domains\Audit\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Audit log entry.
 *
 * Tracks all compliance-relevant actions for regulatory review.
 */
class AuditLog extends Model
{
    use HasUuids;

    protected $fillable = [
        'org_id',
        'user_id',
        'action',
        'entity_type',
        'entity_id',
        'old_values',
        'new_values',
        'ip_address',
        'user_agent',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
            'metadata' => 'array',
        ];
    }
}
