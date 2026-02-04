<?php

declare(strict_types=1);

namespace App\Models\Core;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class RecurringProfile extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id',
        'branch_id',
        'name',
        'profile_type',
        'source_type',
        'source_id',
        'frequency',
        'interval',
        'schedule_config',
        'start_date',
        'end_date',
        'next_run_date',
        'last_run_date',
        'max_occurrences',
        'occurrences_count',
        'auto_send',
        'send_reminder',
        'reminder_days_before',
        'status',
        'notify_on_creation',
        'notify_email',
        'created_by',
    ];

    protected $casts = [
        'schedule_config' => 'array',
        'start_date' => 'date',
        'end_date' => 'date',
        'next_run_date' => 'date',
        'last_run_date' => 'date',
        'interval' => 'integer',
        'max_occurrences' => 'integer',
        'occurrences_count' => 'integer',
        'reminder_days_before' => 'integer',
        'auto_send' => 'boolean',
        'send_reminder' => 'boolean',
        'notify_on_creation' => 'boolean',
    ];

    // Profile types
    public const TYPE_INVOICE = 'invoice';
    public const TYPE_BILL = 'bill';
    public const TYPE_JOURNAL = 'journal_entry';
    public const TYPE_EXPENSE = 'expense';

    // Frequencies
    public const FREQ_DAILY = 'daily';
    public const FREQ_WEEKLY = 'weekly';
    public const FREQ_BIWEEKLY = 'biweekly';
    public const FREQ_MONTHLY = 'monthly';
    public const FREQ_QUARTERLY = 'quarterly';
    public const FREQ_SEMIANNUALLY = 'semiannually';
    public const FREQ_YEARLY = 'yearly';
    public const FREQ_CUSTOM = 'custom';

    // Statuses
    public const STATUS_ACTIVE = 'active';
    public const STATUS_PAUSED = 'paused';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_EXPIRED = 'expired';

    // Relationships

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function logs(): HasMany
    {
        return $this->hasMany(RecurringProfileLog::class);
    }

    // Scopes

    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function scopePaused($query)
    {
        return $query->where('status', self::STATUS_PAUSED);
    }

    public function scopeDueToRun($query, ?Carbon $date = null)
    {
        $date = $date ?? today();
        return $query->where('status', self::STATUS_ACTIVE)
            ->where('next_run_date', '<=', $date)
            ->where(function ($q) use ($date) {
                $q->whereNull('end_date')
                    ->orWhere('end_date', '>=', $date);
            })
            ->where(function ($q) {
                $q->whereNull('max_occurrences')
                    ->orWhereColumn('occurrences_count', '<', 'max_occurrences');
            });
    }

    public function scopeForType($query, string $type)
    {
        return $query->where('profile_type', $type);
    }

    // Status management

    public function activate(): self
    {
        $this->status = self::STATUS_ACTIVE;
        $this->calculateNextRunDate();
        $this->save();
        return $this;
    }

    public function pause(): self
    {
        $this->status = self::STATUS_PAUSED;
        $this->save();
        return $this;
    }

    public function complete(): self
    {
        $this->status = self::STATUS_COMPLETED;
        $this->next_run_date = null;
        $this->save();
        return $this;
    }

    public function expire(): self
    {
        $this->status = self::STATUS_EXPIRED;
        $this->next_run_date = null;
        $this->save();
        return $this;
    }

    // Date calculations

    public function calculateNextRunDate(?Carbon $fromDate = null): self
    {
        $fromDate = $fromDate ?? ($this->last_run_date ?? $this->start_date);

        if ($fromDate instanceof \DateTimeInterface) {
            $fromDate = Carbon::instance($fromDate);
        } else {
            $fromDate = Carbon::parse($fromDate);
        }

        $nextDate = match ($this->frequency) {
            self::FREQ_DAILY => $fromDate->copy()->addDays($this->interval),
            self::FREQ_WEEKLY => $fromDate->copy()->addWeeks($this->interval),
            self::FREQ_BIWEEKLY => $fromDate->copy()->addWeeks(2 * $this->interval),
            self::FREQ_MONTHLY => $this->calculateMonthlyDate($fromDate),
            self::FREQ_QUARTERLY => $fromDate->copy()->addMonths(3 * $this->interval),
            self::FREQ_SEMIANNUALLY => $fromDate->copy()->addMonths(6 * $this->interval),
            self::FREQ_YEARLY => $fromDate->copy()->addYears($this->interval),
            self::FREQ_CUSTOM => $this->calculateCustomDate($fromDate),
            default => $fromDate->copy()->addMonth(),
        };

        // Ensure next date is in the future
        while ($nextDate->lte(today())) {
            $nextDate = $this->advanceDate($nextDate);
        }

        $this->next_run_date = $nextDate;
        return $this;
    }

    protected function calculateMonthlyDate(Carbon $fromDate): Carbon
    {
        $nextDate = $fromDate->copy()->addMonths($this->interval);
        $config = $this->schedule_config ?? [];

        // If specific day of month is set
        if (isset($config['day_of_month'])) {
            $dayOfMonth = min($config['day_of_month'], $nextDate->daysInMonth);
            $nextDate->day($dayOfMonth);
        }

        // If last day of month
        if ($config['last_day_of_month'] ?? false) {
            $nextDate->endOfMonth()->startOfDay();
        }

        return $nextDate;
    }

    protected function calculateCustomDate(Carbon $fromDate): Carbon
    {
        $config = $this->schedule_config ?? [];
        $nextDate = $fromDate->copy();

        if (isset($config['days'])) {
            $nextDate->addDays($config['days']);
        }
        if (isset($config['weeks'])) {
            $nextDate->addWeeks($config['weeks']);
        }
        if (isset($config['months'])) {
            $nextDate->addMonths($config['months']);
        }
        if (isset($config['years'])) {
            $nextDate->addYears($config['years']);
        }

        return $nextDate;
    }

    protected function advanceDate(Carbon $date): Carbon
    {
        return match ($this->frequency) {
            self::FREQ_DAILY => $date->addDays($this->interval),
            self::FREQ_WEEKLY => $date->addWeeks($this->interval),
            self::FREQ_BIWEEKLY => $date->addWeeks(2 * $this->interval),
            self::FREQ_MONTHLY => $date->addMonths($this->interval),
            self::FREQ_QUARTERLY => $date->addMonths(3 * $this->interval),
            self::FREQ_SEMIANNUALLY => $date->addMonths(6 * $this->interval),
            self::FREQ_YEARLY => $date->addYears($this->interval),
            self::FREQ_CUSTOM => $this->calculateCustomDate($date),
            default => $date->addMonth(),
        };
    }

    // Helpers

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function isPaused(): bool
    {
        return $this->status === self::STATUS_PAUSED;
    }

    public function isDueToRun(?Carbon $date = null): bool
    {
        if (!$this->isActive()) {
            return false;
        }

        $date = $date ?? today();

        if ($this->next_run_date && $this->next_run_date->gt($date)) {
            return false;
        }

        if ($this->end_date && $this->end_date->lt($date)) {
            return false;
        }

        if ($this->max_occurrences && $this->occurrences_count >= $this->max_occurrences) {
            return false;
        }

        return true;
    }

    public function incrementOccurrence(): self
    {
        $this->occurrences_count++;
        $this->last_run_date = today();
        $this->calculateNextRunDate();

        // Check if should complete
        if ($this->max_occurrences && $this->occurrences_count >= $this->max_occurrences) {
            $this->complete();
        } elseif ($this->end_date && $this->next_run_date && $this->next_run_date->gt($this->end_date)) {
            $this->expire();
        } else {
            $this->save();
        }

        return $this;
    }

    public function getFrequencyLabel(): string
    {
        return match ($this->frequency) {
            self::FREQ_DAILY => $this->interval === 1 ? 'Daily' : "Every {$this->interval} days",
            self::FREQ_WEEKLY => $this->interval === 1 ? 'Weekly' : "Every {$this->interval} weeks",
            self::FREQ_BIWEEKLY => 'Bi-weekly',
            self::FREQ_MONTHLY => $this->interval === 1 ? 'Monthly' : "Every {$this->interval} months",
            self::FREQ_QUARTERLY => 'Quarterly',
            self::FREQ_SEMIANNUALLY => 'Semi-annually',
            self::FREQ_YEARLY => $this->interval === 1 ? 'Yearly' : "Every {$this->interval} years",
            self::FREQ_CUSTOM => 'Custom',
            default => ucfirst($this->frequency),
        };
    }

    public function getRemainingOccurrences(): ?int
    {
        if (!$this->max_occurrences) {
            return null; // Unlimited
        }
        return max(0, $this->max_occurrences - $this->occurrences_count);
    }

    public static function getFrequencies(): array
    {
        return [
            self::FREQ_DAILY => 'Daily',
            self::FREQ_WEEKLY => 'Weekly',
            self::FREQ_BIWEEKLY => 'Bi-weekly',
            self::FREQ_MONTHLY => 'Monthly',
            self::FREQ_QUARTERLY => 'Quarterly',
            self::FREQ_SEMIANNUALLY => 'Semi-annually',
            self::FREQ_YEARLY => 'Yearly',
            self::FREQ_CUSTOM => 'Custom',
        ];
    }
}
