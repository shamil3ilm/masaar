<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Fatoora\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Submission State Transition Log.
 *
 * Immutable audit trail for all submission state changes.
 * Used for debugging, compliance, and analytics.
 */
class SubmissionStateLog extends Model
{
    use HasUuids;

    protected $table = 'submission_state_logs';

    protected $fillable = [
        'submission_id',
        'from_state',
        'to_state',
        'trigger',
        'context',
        'notes',
        'actor_type',
        'actor_id',
        'ip_address',
    ];

    protected function casts(): array
    {
        return [
            'context' => 'array',
        ];
    }

    /**
     * Boot method - prevent deletion and updates.
     *
     * COMPLIANCE: State logs are append-only audit records.
     * They cannot be modified or deleted per ZATCA requirements.
     */
    protected static function boot(): void
    {
        parent::boot();

        // Prevent any deletion
        static::deleting(function ($model) {
            throw new \RuntimeException(
                'SubmissionStateLog records cannot be deleted. ' .
                'This is a ZATCA compliance requirement for audit trails.'
            );
        });

        // Prevent any updates - logs are append-only
        static::updating(function ($model) {
            throw new \RuntimeException(
                'SubmissionStateLog records cannot be modified. ' .
                'This is a ZATCA compliance requirement for audit integrity.'
            );
        });
    }

    /**
     * Trigger types.
     */
    public const TRIGGER_API_CALL = 'api_call';
    public const TRIGGER_QUEUE_JOB = 'queue_job';
    public const TRIGGER_MANUAL = 'manual';
    public const TRIGGER_TIMEOUT = 'timeout';
    public const TRIGGER_RETRY = 'retry';
    public const TRIGGER_ZATCA = 'zatca';
    public const TRIGGER_ERROR = 'error';

    /**
     * Actor types.
     */
    public const ACTOR_USER = 'user';
    public const ACTOR_SYSTEM = 'system';
    public const ACTOR_ZATCA = 'zatca';

    /**
     * Get the parent submission.
     */
    public function submission(): BelongsTo
    {
        return $this->belongsTo(InvoiceSubmission::class, 'submission_id');
    }

    /**
     * Check if this was an initial creation.
     */
    public function isInitial(): bool
    {
        return $this->from_state === null;
    }

    /**
     * Check if this was a state change.
     */
    public function isStateChange(): bool
    {
        return $this->from_state !== $this->to_state;
    }

    /**
     * Check if this transition was to a terminal state.
     */
    public function isTerminal(): bool
    {
        return in_array($this->to_state, InvoiceSubmission::TERMINAL_STATES, true);
    }

    /**
     * Get duration from previous state (if applicable).
     */
    public function getDurationFromPrevious(): ?int
    {
        $previous = static::where('submission_id', $this->submission_id)
            ->where('created_at', '<', $this->created_at)
            ->orderByDesc('created_at')
            ->first();

        if (!$previous) {
            return null;
        }

        return $this->created_at->diffInSeconds($previous->created_at);
    }

    /**
     * Scope for submission.
     */
    public function scopeForSubmission($query, string $submissionId)
    {
        return $query->where('submission_id', $submissionId);
    }

    /**
     * Scope for trigger type.
     */
    public function scopeByTrigger($query, string $trigger)
    {
        return $query->where('trigger', $trigger);
    }
}
