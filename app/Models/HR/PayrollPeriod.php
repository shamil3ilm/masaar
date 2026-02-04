<?php

declare(strict_types=1);

namespace App\Models\HR;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PayrollPeriod extends Model
{
    use BelongsToOrganization;

    public const STATUS_OPEN = 'open';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_PROCESSED = 'processed';
    public const STATUS_CLOSED = 'closed';

    protected $fillable = [
        'organization_id',
        'name',
        'start_date',
        'end_date',
        'payment_date',
        'status',
        'processed_by',
        'processed_at',
        'closed_by',
        'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'payment_date' => 'date',
            'processed_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function payslips(): HasMany
    {
        return $this->hasMany(Payslip::class);
    }

    public function processor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by');
    }

    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }

    public function isProcessed(): bool
    {
        return in_array($this->status, [self::STATUS_PROCESSED, self::STATUS_CLOSED], true);
    }

    public function isClosed(): bool
    {
        return $this->status === self::STATUS_CLOSED;
    }

    public function canBeProcessed(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }

    public function canBeClosed(): bool
    {
        return $this->status === self::STATUS_PROCESSED;
    }

    public function getWorkingDaysCount(): int
    {
        // Simplified - would need to account for holidays and weekends per org settings
        return $this->start_date->diffInDaysFiltered(function ($date) {
            return $date->isWeekday();
        }, $this->end_date) + 1;
    }

    public function getTotalPayroll(): float
    {
        return (float) $this->payslips()
            ->whereIn('status', [Payslip::STATUS_APPROVED, Payslip::STATUS_PAID])
            ->sum('net_salary');
    }

    public function getEmployeeCount(): int
    {
        return $this->payslips()->count();
    }

    public function scopeOpen($query)
    {
        return $query->where('status', self::STATUS_OPEN);
    }

    public function scopeProcessed($query)
    {
        return $query->whereIn('status', [self::STATUS_PROCESSED, self::STATUS_CLOSED]);
    }

    public function scopeClosed($query)
    {
        return $query->where('status', self::STATUS_CLOSED);
    }

    public function scopeForDate($query, $date)
    {
        return $query->where('start_date', '<=', $date)
            ->where('end_date', '>=', $date);
    }
}
