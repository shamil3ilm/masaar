<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Fatoora\Models;

use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Concerns\BelongsToTenant;
use App\Domains\Organization\Models\Organization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Submission Idempotency Record.
 *
 * Prevents duplicate ZATCA submissions by tracking requests
 * with idempotency keys. Supports response replay for duplicate
 * requests within the idempotency window.
 */
class SubmissionIdempotency extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $table = 'submission_idempotency';

    protected $fillable = [
        'idempotency_key',
        'invoice_id',
        'organization_id',
        'request_hash',
        'endpoint',
        'method',
        'status',
        'http_status_code',
        'response_body',
        'response_headers',
        'zatca_request_id',
        'zatca_clearance_status',
        'zatca_errors',
        'attempt_count',
        'first_attempt_at',
        'last_attempt_at',
        'completed_at',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'response_body' => 'array',
            'response_headers' => 'array',
            'http_status_code' => 'integer',
            'attempt_count' => 'integer',
            'first_attempt_at' => 'datetime',
            'last_attempt_at' => 'datetime',
            'completed_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * Statuses.
     */
    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_EXPIRED = 'expired';

    /**
     * Get the invoice.
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * Get the organization.
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Get the associated submission.
     */
    public function submission(): HasOne
    {
        return $this->hasOne(InvoiceSubmission::class, 'idempotency_id');
    }

    /**
     * Check if the idempotency key is still valid.
     */
    public function isValid(): bool
    {
        return $this->expires_at->isFuture();
    }

    /**
     * Check if request is still processing.
     */
    public function isProcessing(): bool
    {
        return $this->status === self::STATUS_PROCESSING;
    }

    /**
     * Check if request was completed.
     */
    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    /**
     * Check if request failed.
     */
    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    /**
     * Check if the request hash matches.
     */
    public function matchesHash(string $hash): bool
    {
        return $this->request_hash === $hash;
    }

    /**
     * Mark as completed with response.
     */
    public function markCompleted(int $httpStatus, array $response): void
    {
        $this->update([
            'status' => self::STATUS_COMPLETED,
            'http_status_code' => $httpStatus,
            'response_body' => $response,
            'completed_at' => now(),
        ]);
    }

    /**
     * Mark as failed.
     */
    public function markFailed(string $error): void
    {
        $this->update([
            'status' => self::STATUS_FAILED,
            'zatca_errors' => $error,
            'completed_at' => now(),
        ]);
    }

    /**
     * Expire the idempotency key.
     */
    public function expire(): void
    {
        $this->update([
            'status' => self::STATUS_EXPIRED,
            'expires_at' => now(),
        ]);
    }

    /**
     * Increment attempt count.
     */
    public function incrementAttempt(): void
    {
        $this->increment('attempt_count');
        $this->update(['last_attempt_at' => now()]);
    }

    /**
     * Scope for valid (non-expired) records.
     */
    public function scopeValid($query)
    {
        return $query->where('expires_at', '>', now());
    }

    /**
     * Scope for expired records.
     */
    public function scopeExpired($query)
    {
        return $query->where('expires_at', '<=', now());
    }

    /**
     * Find by idempotency key.
     */
    public static function findByKey(string $key): ?self
    {
        return static::where('idempotency_key', $key)->first();
    }
}
