<?php

namespace App\Domains\Audit\Models;

use App\Domains\Audit\Services\AuditChain;
use App\Domains\Organization\Concerns\RecordsActors;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Audit log entry.
 *
 * Tracks all compliance-relevant actions for regulatory review.
 *
 * Written once and never changed or removed: the solution must not allow
 * its logs to be modified or deleted (ZATCA E-Invoicing Detailed Guidelines
 * 5.6, prohibited functions, p27). Each entry keeps the request it was
 * made in (RecordsActors), so it can be followed to what that request did.
 *
 * Each entry is chained to the one before it in its organization's chain
 * (AuditChain): a hash of its content and its predecessor's hash, checked by
 * `platform:verify-audit-chain`. The database refuses an update or a delete
 * as well (migration 1817, MySQL).
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
        // Last of the creating listeners (RecordsActors' run first), so the
        // hash covers the request id as well. The id and the time are set
        // here rather than by the insert, which sets them after this runs.
        static::creating(function (AuditLog $entry): void {
            $entry->setUniqueIds();

            if ($entry->created_at === null) {
                $entry->setCreatedAt($entry->freshTimestamp());
            }

            AuditChain::append($entry);
        });

        static::updating(function (): void {
            throw new \RuntimeException('Audit log entries cannot be changed.');
        });

        static::deleting(function (): void {
            throw new \RuntimeException('Audit log entries cannot be deleted.');
        });
    }

    /**
     * One transaction for the chain's head and the entry it places, so the
     * head's lock is held until the entry is written (AuditChain::append).
     *
     * @param  Builder<AuditLog>  $query
     */
    protected function performInsert(Builder $query): bool
    {
        return DB::transaction(fn (): bool => parent::performInsert($query));
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
