<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Fatoora\Models;

use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Concerns\BelongsToTenant;
use App\Domains\Organization\Models\Organization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An invoice signed while ZATCA was unreachable, held for later submission.
 *
 * ZATCA requires a taxpayer to keep issuing during an outage and report
 * afterwards, so the signed XML and QR are stored here at issue time and the
 * submission is retried until it lands.
 */
class OfflineItem extends Model
{
    use BelongsToTenant, HasUuids;

    protected $table = 'offline_queue';

    protected $fillable = [
        'org_id',
        'invoice_id',
        'signed_xml',
        'invoice_hash',
        'qr_code',
        'state',
        'priority',
        'attempts',
        'max_attempts',
        'next_attempt_at',
        'last_error',
        'zatca_response',
        'queued_at',
        'started_at',
        'completed_at',
        'failed_at',
        'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'max_attempts' => 'integer',
            'zatca_response' => 'array',
            'next_attempt_at' => 'datetime',
            'queued_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'failed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public const PENDING = 'pending';

    public const PROCESSING = 'processing';

    public const COMPLETED = 'completed';

    public const FAILED = 'failed';

    public const CANCELLED = 'cancelled';

    public function org(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * Waiting to go out, and due — an item scheduled for a later retry is not
     * yet claimable.
     */
    public function scopeDue(Builder $query): Builder
    {
        return $query->where('state', self::PENDING)
            ->where(fn (Builder $q) => $q->whereNull('next_attempt_at')
                ->orWhere('next_attempt_at', '<=', now()));
    }
}
