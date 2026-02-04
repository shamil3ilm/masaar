<?php

declare(strict_types=1);

namespace App\Models\Accounting;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccountingPeriod extends Model
{
    protected $fillable = [
        'fiscal_year_id',
        'period_number',
        'period_type',
        'start_date',
        'end_date',
        'is_closed',
        'closed_at',
        'closed_by',
    ];

    protected function casts(): array
    {
        return [
            'period_number' => 'integer',
            'start_date' => 'date',
            'end_date' => 'date',
            'is_closed' => 'boolean',
            'closed_at' => 'datetime',
        ];
    }

    public function fiscalYear(): BelongsTo
    {
        return $this->belongsTo(FiscalYear::class);
    }

    public function closedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    /**
     * Check if a date falls within this period.
     */
    public function containsDate($date): bool
    {
        $date = is_string($date) ? now()->parse($date) : $date;
        return $date->between($this->start_date, $this->end_date);
    }

    /**
     * Get period name (e.g., "January 2024" or "Q1 2024").
     */
    public function getName(): string
    {
        if ($this->period_type === 'quarter') {
            return "Q{$this->period_number} " . $this->start_date->format('Y');
        }

        return $this->start_date->format('F Y');
    }

    /**
     * Close the period.
     */
    public function close(?int $userId = null): void
    {
        $this->update([
            'is_closed' => true,
            'closed_at' => now(),
            'closed_by' => $userId ?? auth()->id(),
        ]);
    }

    public function scopeOpen($query)
    {
        return $query->where('is_closed', false);
    }

    public function scopeForDate($query, $date)
    {
        $date = is_string($date) ? now()->parse($date) : $date;

        return $query
            ->whereDate('start_date', '<=', $date)
            ->whereDate('end_date', '>=', $date);
    }
}
