<?php

declare(strict_types=1);

namespace App\Domains\Compliance\FTA\Models;

use App\Domains\Organization\Concerns\BelongsToTenant;
use App\Domains\Compliance\FTA\Enums\FtaStatus;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Tracks the lifecycle of a UAE FTA e-invoice submission.
 *
 * State flow:
 *   draft → queued → submitted → accepted
 *                             → pending_review → accepted|rejected
 *                             → rejected → queued (retry)
 *                   → failed  → queued (retry) | cancelled
 */
class FtaSubmission extends Model
{
    use BelongsToTenant;
    use HasUuids;
    use SoftDeletes;

    protected $table = 'uae_fta_submissions';

    protected $fillable = [
        'invoice_id',
        'organization_id',
        'status',
        'fta_submission_id',     // FTA-assigned reference
        'fta_validation_status',
        'fta_warnings',
        'fta_errors',
        'document_type',         // 380|381|383
        'invoice_xml',           // Peppol UBL XML
        'retry_count',
        'max_retries',
        'next_retry_at',
        'last_error_message',
        'submitted_at',
        'accepted_at',
    ];

    protected function casts(): array
    {
        return [
            'status'         => FtaStatus::class,
            'fta_warnings'   => 'array',
            'fta_errors'     => 'array',
            'retry_count'    => 'integer',
            'max_retries'    => 'integer',
            'next_retry_at'  => 'datetime',
            'submitted_at'   => 'datetime',
            'accepted_at'    => 'datetime',
        ];
    }

    // Prevent permanent deletion — audit trail requirement
    protected static function boot(): void
    {
        parent::boot();

        static::forceDeleting(function (): void {
            throw new \RuntimeException('UAE FTA submissions cannot be permanently deleted (compliance audit trail).');
        });
    }

    // ----------------------------------------------------------------
    // Relationships
    // ----------------------------------------------------------------

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    // ----------------------------------------------------------------
    // State helpers
    // ----------------------------------------------------------------

    public function canTransitionTo(FtaStatus $next): bool
    {
        return $this->status->canTransitionTo($next);
    }

    public function isAccepted(): bool
    {
        return $this->status === FtaStatus::Accepted;
    }

    public function canRetry(): bool
    {
        return in_array($this->status, [FtaStatus::Failed, FtaStatus::Rejected], true)
            && $this->retry_count < $this->max_retries;
    }
}
