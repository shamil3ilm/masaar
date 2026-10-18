<?php

namespace App\Domains\Audit\Models;

use App\Domains\Organization\Concerns\RecordsActors;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Audit log entry.
 *
 * Tracks all compliance-relevant actions for regulatory review.
 *
 * Written once and never changed or removed: the solution must not allow
 * its logs to be modified or deleted (ZATCA E-Invoicing Detailed Guidelines
 * 5.6, prohibited functions, p27). Each entry keeps the request it was
 * made in (RecordsActors), so it can be followed to what that request did.
 */
class AuditLog extends Model
{
    use HasUuids;
    use RecordsActors;

    public const UPDATED_AT = null;

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

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new \RuntimeException('Audit log entries cannot be changed.');
        });

        static::deleting(function (): void {
            throw new \RuntimeException('Audit log entries cannot be deleted.');
        });
    }

    /**
     * Who acted is user_id, set by AuditService; this keeps the request.
     *
     * @return array<string, string>
     */
    public function attributionColumns(): array
    {
        return ['request_id' => 'request_id'];
    }

    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
            'metadata' => 'array',
        ];
    }
}
