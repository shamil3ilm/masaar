<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Zatca\Models;

use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Invoice Submission with State Machine.
 *
 * Tracks the lifecycle of a ZATCA submission through
 * various states with full audit trail.
 *
 * State Flow:
 * draft -> queued -> pending_submission -> submitted -> (cleared|reported|warning|rejected|failed)
 *                                                   \-> cancelled (from draft/queued only)
 */
class InvoiceSubmission extends Model
{
    use HasUuids;
    use SoftDeletes;

    protected $table = 'invoice_submissions';

    protected $fillable = [
        'invoice_id',
        'organization_id',
        'idempotency_id',
        'state',
        'previous_state',
        'state_changed_at',
        'zatca_uuid',
        'zatca_invoice_hash',
        'clearance_status',
        'reporting_status',
        'zatca_warnings',
        'zatca_errors',
        'submission_type',
        'submission_mode',
        'queue_job_id',
        'retry_count',
        'max_retries',
        'next_retry_at',
        'last_error_code',
        'last_error_message',
        'queued_at',
        'submitted_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'zatca_warnings' => 'array',
            'zatca_errors' => 'array',
            'retry_count' => 'integer',
            'max_retries' => 'integer',
            'state_changed_at' => 'datetime',
            'next_retry_at' => 'datetime',
            'queued_at' => 'datetime',
            'submitted_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * Valid state transitions.
     */
    public const STATE_TRANSITIONS = [
        'draft' => ['queued', 'pending_submission', 'cancelled'],
        'queued' => ['pending_submission', 'cancelled', 'failed'],
        'pending_submission' => ['submitted', 'failed'],
        'submitted' => ['cleared', 'reported', 'warning', 'rejected', 'failed'],
        'warning' => [], // Terminal state (accepted with warnings)
        'cleared' => [], // Terminal state
        'reported' => [], // Terminal state
        'rejected' => ['pending_submission'], // Can retry
        'failed' => ['pending_submission', 'cancelled'], // Can retry or cancel
        'cancelled' => [], // Terminal state
    ];

    /**
     * Terminal states.
     */
    public const TERMINAL_STATES = ['cleared', 'reported', 'warning', 'cancelled'];

    /**
     * Get the invoice being submitted.
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
     * Get the idempotency record.
     */
    public function idempotency(): BelongsTo
    {
        return $this->belongsTo(SubmissionIdempotency::class, 'idempotency_id');
    }

    /**
     * Get state transition logs.
     */
    public function stateLogs(): HasMany
    {
        return $this->hasMany(SubmissionStateLog::class, 'submission_id');
    }

    /**
     * Check if submission can transition to given state.
     */
    public function canTransitionTo(string $newState): bool
    {
        $allowedTransitions = self::STATE_TRANSITIONS[$this->state] ?? [];
        return in_array($newState, $allowedTransitions, true);
    }

    /**
     * Check if submission is in a terminal state.
     */
    public function isTerminal(): bool
    {
        return in_array($this->state, self::TERMINAL_STATES, true);
    }

    /**
     * Check if submission was successful.
     */
    public function isSuccessful(): bool
    {
        return in_array($this->state, ['cleared', 'reported', 'warning'], true);
    }

    /**
     * Check if submission can be retried.
     */
    public function canRetry(): bool
    {
        if (!in_array($this->state, ['failed', 'rejected'], true)) {
            return false;
        }

        return $this->retry_count < $this->max_retries;
    }

    /**
     * Check if submission can be cancelled.
     */
    public function canCancel(): bool
    {
        return in_array($this->state, ['draft', 'queued', 'failed'], true);
    }

    /**
     * Check if submission is B2B (clearance).
     */
    public function isClearance(): bool
    {
        return $this->submission_type === 'clearance';
    }

    /**
     * Check if submission is B2C (reporting).
     */
    public function isReporting(): bool
    {
        return $this->submission_type === 'reporting';
    }

    /**
     * Scope for pending retries.
     */
    public function scopeReadyForRetry($query)
    {
        return $query->where('state', 'failed')
            ->whereNotNull('next_retry_at')
            ->where('next_retry_at', '<=', now())
            ->where('retry_count', '<', $query->raw('max_retries'));
    }

    /**
     * Scope for in-progress submissions.
     */
    public function scopeInProgress($query)
    {
        return $query->whereIn('state', ['queued', 'pending_submission', 'submitted']);
    }

    /**
     * Scope for organization.
     */
    public function scopeForOrganization($query, string $organizationId)
    {
        return $query->where('organization_id', $organizationId);
    }
}
