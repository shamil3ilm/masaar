<?php

declare(strict_types=1);

namespace App\Models\Accounting;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FiscalYear extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id',
        'name',
        'start_date',
        'end_date',
        'is_current',
        'is_closed',
        'closed_at',
        'closed_by',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'is_current' => 'boolean',
            'is_closed' => 'boolean',
            'closed_at' => 'datetime',
        ];
    }

    public function closedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function periods(): HasMany
    {
        return $this->hasMany(AccountingPeriod::class);
    }

    public function journalEntries(): HasMany
    {
        return $this->hasMany(JournalEntry::class);
    }

    public function openingBalances(): HasMany
    {
        return $this->hasMany(AccountOpeningBalance::class);
    }

    /**
     * Check if a date falls within this fiscal year.
     */
    public function containsDate($date): bool
    {
        $date = is_string($date) ? now()->parse($date) : $date;
        return $date->between($this->start_date, $this->end_date);
    }

    /**
     * Get the current fiscal year for the organization.
     */
    public static function current(int $organizationId): ?static
    {
        return static::where('organization_id', $organizationId)
            ->where('is_current', true)
            ->first();
    }

    /**
     * Get fiscal year for a specific date.
     */
    public static function forDate(int $organizationId, $date): ?static
    {
        $date = is_string($date) ? now()->parse($date) : $date;

        return static::where('organization_id', $organizationId)
            ->whereDate('start_date', '<=', $date)
            ->whereDate('end_date', '>=', $date)
            ->first();
    }

    /**
     * Close the fiscal year.
     */
    public function close(?int $userId = null): void
    {
        $this->update([
            'is_closed' => true,
            'is_current' => false,
            'closed_at' => now(),
            'closed_by' => $userId ?? auth()->id(),
        ]);
    }

    /**
     * Set as current fiscal year (only one can be current).
     */
    public function setAsCurrent(): void
    {
        // Remove current flag from other years
        static::where('organization_id', $this->organization_id)
            ->where('id', '!=', $this->id)
            ->update(['is_current' => false]);

        $this->update(['is_current' => true]);
    }

    public function scopeCurrent($query)
    {
        return $query->where('is_current', true);
    }

    public function scopeOpen($query)
    {
        return $query->where('is_closed', false);
    }
}
