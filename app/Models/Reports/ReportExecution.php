<?php

declare(strict_types=1);

namespace App\Models\Reports;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReportExecution extends Model
{
    use HasFactory, BelongsToOrganization;

    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'organization_id',
        'saved_report_id',
        'user_id',
        'report_type',
        'parameters',
        'status',
        'file_path',
        'file_format',
        'file_size',
        'row_count',
        'execution_time_ms',
        'error_message',
        'started_at',
        'completed_at',
        'expires_at',
    ];

    protected $casts = [
        'parameters' => 'array',
        'file_size' => 'integer',
        'row_count' => 'integer',
        'execution_time_ms' => 'integer',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function savedReport(): BelongsTo
    {
        return $this->belongsTo(SavedReport::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Mark execution as started.
     */
    public function markAsStarted(): void
    {
        $this->update([
            'status' => self::STATUS_PROCESSING,
            'started_at' => now(),
        ]);
    }

    /**
     * Mark execution as completed.
     */
    public function markAsCompleted(string $filePath, string $format, int $fileSize, int $rowCount): void
    {
        $executionTime = $this->started_at
            ? now()->diffInMilliseconds($this->started_at)
            : null;

        $this->update([
            'status' => self::STATUS_COMPLETED,
            'file_path' => $filePath,
            'file_format' => $format,
            'file_size' => $fileSize,
            'row_count' => $rowCount,
            'execution_time_ms' => $executionTime,
            'completed_at' => now(),
            'expires_at' => now()->addDays(7), // Keep files for 7 days
        ]);
    }

    /**
     * Mark execution as failed.
     */
    public function markAsFailed(string $errorMessage): void
    {
        $this->update([
            'status' => self::STATUS_FAILED,
            'error_message' => $errorMessage,
            'completed_at' => now(),
        ]);
    }

    /**
     * Check if file is still available.
     */
    public function isFileAvailable(): bool
    {
        if (!$this->file_path) {
            return false;
        }

        if ($this->expires_at && $this->expires_at->isPast()) {
            return false;
        }

        return file_exists(storage_path('app/' . $this->file_path));
    }

    /**
     * Scope for expired executions.
     */
    public function scopeExpired($query)
    {
        return $query->where('expires_at', '<', now());
    }
}
