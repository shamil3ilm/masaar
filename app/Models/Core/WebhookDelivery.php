<?php

declare(strict_types=1);

namespace App\Models\Core;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class WebhookDelivery extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'uuid',
        'webhook_id',
        'event_type',
        'payload',
        'status',
        'http_status',
        'response_body',
        'response_headers',
        'duration_ms',
        'attempt',
        'next_retry_at',
        'error_message',
    ];

    protected $casts = [
        'payload' => 'array',
        'response_headers' => 'array',
        'next_retry_at' => 'datetime',
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (self $model) {
            if (empty($model->uuid)) {
                $model->uuid = Str::uuid()->toString();
            }
        });
    }

    public function webhook(): BelongsTo
    {
        return $this->belongsTo(Webhook::class);
    }

    /**
     * Check if delivery was successful.
     */
    public function isSuccess(): bool
    {
        return $this->status === self::STATUS_SUCCESS;
    }

    /**
     * Check if delivery failed.
     */
    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    /**
     * Check if delivery is pending retry.
     */
    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /**
     * Check if should retry.
     */
    public function shouldRetry(): bool
    {
        if ($this->status !== self::STATUS_FAILED) {
            return false;
        }

        $webhook = $this->webhook;
        return $this->attempt < $webhook->retry_count;
    }

    /**
     * Get next retry delay based on attempt (exponential backoff).
     */
    public function getRetryDelay(): int
    {
        // Exponential backoff: 1min, 5min, 15min, 30min, 60min
        $delays = [60, 300, 900, 1800, 3600];
        $index = min($this->attempt - 1, count($delays) - 1);

        return $delays[$index];
    }

    /**
     * Mark as success.
     */
    public function markAsSuccess(int $httpStatus, ?string $responseBody, ?array $responseHeaders, int $durationMs): void
    {
        $this->update([
            'status' => self::STATUS_SUCCESS,
            'http_status' => $httpStatus,
            'response_body' => $responseBody ? substr($responseBody, 0, 10000) : null,
            'response_headers' => $responseHeaders,
            'duration_ms' => $durationMs,
            'error_message' => null,
        ]);

        $this->webhook->recordSuccess();
    }

    /**
     * Mark as failed.
     */
    public function markAsFailed(string $errorMessage, ?int $httpStatus = null, ?string $responseBody = null, int $durationMs = 0): void
    {
        $shouldRetry = $this->shouldRetry();

        $this->update([
            'status' => $shouldRetry ? self::STATUS_PENDING : self::STATUS_FAILED,
            'http_status' => $httpStatus,
            'response_body' => $responseBody ? substr($responseBody, 0, 10000) : null,
            'duration_ms' => $durationMs,
            'error_message' => $errorMessage,
            'attempt' => $this->attempt + ($shouldRetry ? 1 : 0),
            'next_retry_at' => $shouldRetry ? now()->addSeconds($this->getRetryDelay()) : null,
        ]);

        if (!$shouldRetry) {
            $this->webhook->recordFailure();
        }
    }
}
