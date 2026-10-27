<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\SaudiArabia\Models;

use App\Domains\Compliance\Countries\SaudiArabia\Exceptions\IllegalSubmissionMoveException;
use App\Domains\Compliance\Countries\SaudiArabia\Filing\SubmissionState;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Concerns\BelongsToTenant;
use App\Domains\Organization\Concerns\RecordsActors;
use App\Domains\Organization\Models\Organization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Invoice Submission with State Machine.
 *
 * Tracks the lifecycle of a ZATCA submission through
 * various states with full audit trail.
 *
 * Its states and the moves between them are SubmissionState's, and
 * SubmissionLedger makes every move.
 */
class InvoiceSubmission extends Model
{
    use BelongsToTenant;
    use HasUuids;
    use RecordsActors;

    protected $table = 'invoice_submissions';

    protected $fillable = [
        'invoice_id',
        'org_id',
        'idempotency_id',
        'state',
        'previous_state',
        'state_changed_at',
        'zatca_uuid',
        'invoice_hash',
        'clearance_status',
        'clearance_state',           // Added: ZATCA clearance state tracking
        'cleared_at',    // Added: When clearance was confirmed
        'check_count',     // Added: Number of status checks
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
        'last_error',
        'queued_at',
        'signed_at',           // When XAdES signature was applied (authoritative time)
        'submitted_at',
        'completed_at',
    ];

    /**
     * A compliance record: who started the filing and in which request,
     * never who changed it after.
     *
     * @return array<string, string>
     */
    public function attributionColumns(): array
    {
        return ['created_by' => 'created_by', 'request_id' => 'request_id'];
    }

    protected function casts(): array
    {
        return [
            'zatca_warnings' => 'array',
            'zatca_errors' => 'array',
            'retry_count' => 'integer',
            'max_retries' => 'integer',
            'check_count' => 'integer',
            'state_changed_at' => 'datetime',
            'cleared_at' => 'datetime',
            'next_retry_at' => 'datetime',
            'queued_at' => 'datetime',
            'signed_at' => 'datetime',
            'submitted_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * Boot method - prevent hard deletion and updates on terminal states.
     *
     * COMPLIANCE: ZATCA submissions are immutable once completed.
     * They serve as legal evidence of tax compliance.
     */
    protected static function boot(): void
    {
        parent::boot();

        // Never deleted, softly or otherwise: a submission is the evidence of
        // what was filed (ZATCA Detailed Guidelines p26, p27). Its table has
        // a deleted_at from 0140 that nothing ever wrote; the model no longer
        // soft-deletes, so nothing can reach it.
        static::deleting(function ($model) {
            throw new \RuntimeException(
                'InvoiceSubmission records cannot be deleted. '.
                'This is a ZATCA compliance requirement to preserve audit trails.'
            );
        });

        // Prevent updates on terminal states
        static::updating(function (InvoiceSubmission $model) {
            // Get the original state before any changes
            $originalState = $model->getOriginal('state');

            // If original state is terminal (cleared/reported/warning), block updates
            // Exception: allow state_changed_at updates for logging purposes
            if (in_array($originalState, self::TERMINAL_STATES, true)) {
                $changedAttributes = array_keys($model->getDirty());
                $allowedChanges = ['updated_at']; // Only timestamp updates allowed

                $disallowedChanges = array_diff($changedAttributes, $allowedChanges);
                if (! empty($disallowedChanges)) {
                    throw new \RuntimeException(
                        'InvoiceSubmission in terminal state cannot be modified. '.
                        'This is a ZATCA compliance requirement. Attempted changes: '.
                        implode(', ', $disallowedChanges)
                    );
                }
            }

            // Behind SubmissionLedger, which checks and logs each move: a
            // write that reaches the row some other way still keeps to the
            // table.
            if ($model->isDirty('state')) {
                $from = SubmissionState::tryFrom((string) $originalState);
                $to = SubmissionState::tryFrom((string) $model->state);

                if ($from === null || $to === null || ! $from->allows($to)) {
                    throw IllegalSubmissionMoveException::between($originalState, (string) $model->state, (string) $model->id);
                }
            }
        });
    }

    /**
     * Terminal states.
     */
    public const TERMINAL_STATES = ['cleared', 'reported', 'warning', 'cancelled'];

    /**
     * States in which a submission is on its way to ZATCA.
     */
    public const IN_FLIGHT_STATES = ['queued', 'pending_submission', 'submitted'];

    /**
     * States in which ZATCA has accepted the document.
     */
    public const ACCEPTED_STATES = ['cleared', 'reported', 'warning'];

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
    public function org(): BelongsTo
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
     * Check if submission is in a terminal state.
     */
    public function isTerminal(): bool
    {
        return in_array($this->state, self::TERMINAL_STATES, true);
    }

    /**
     * Check if submission can be retried.
     */
    public function canRetry(): bool
    {
        if (! in_array($this->state, ['failed', 'rejected'], true)) {
            return false;
        }

        return $this->retry_count < $this->max_retries;
    }

    /**
     * Whether a person may send it again (SubmissionTracker::resend): a
     * failure, or a clearance held past the safe delay. Rejected is a
     * verdict, which the same document earns again.
     */
    public function isResendable(): bool
    {
        return $this->state === SubmissionState::Failed->value || $this->isHeld();
    }

    /**
     * A clearance sent with no answer recorded, unmoved for longer than an
     * answer takes (the stale window). It is reconciled by sending the same
     * signed document again - same UUID, hash and counter, never re-signed -
     * because ZATCA does not reject the same invoice submitted twice
     * (Detailed Guidelines, FAQ "Can I submit the same invoice twice?"), so
     * its answer settles whether the first attempt cleared.
     */
    public function isHeld(): bool
    {
        if ($this->state !== SubmissionState::Submitted->value || ! $this->isClearance()) {
            return false;
        }

        $movedAt = $this->state_changed_at ?? $this->created_at;

        return $movedAt !== null && $movedAt->lt(self::staleBefore());
    }

    /**
     * Check if submission is B2B (clearance).
     */
    public function isClearance(): bool
    {
        return $this->submission_type === 'clearance';
    }

    /**
     * In flight and not moved for longer than a submission takes: a worker
     * died, a job was lost, a request was killed. Nothing is coming back for
     * it, so fatoora:recover-submissions has to.
     */
    public function scopeStale(Builder $query): Builder
    {
        $cutoff = self::staleBefore();

        return $query->whereIn('state', self::IN_FLIGHT_STATES)
            ->where(fn (Builder $q) => $q->where('state_changed_at', '<', $cutoff)
                ->orWhere(fn (Builder $q) => $q->whereNull('state_changed_at')->where('created_at', '<', $cutoff)));
    }

    /**
     * In flight and recently moved: genuinely under way.
     */
    public function scopeLive(Builder $query): Builder
    {
        $cutoff = self::staleBefore();

        return $query->whereIn('state', self::IN_FLIGHT_STATES)
            ->where(fn (Builder $q) => $q->where('state_changed_at', '>=', $cutoff)
                ->orWhere(fn (Builder $q) => $q->whereNull('state_changed_at')->where('created_at', '>=', $cutoff)));
    }

    /**
     * Before this, an in-flight submission is stale.
     */
    public static function staleBefore(): Carbon
    {
        return now()->subMinutes((int) config('fatoora.recovery.stale_minutes', 15));
    }
}
