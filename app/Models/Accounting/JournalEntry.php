<?php

declare(strict_types=1);

namespace App\Models\Accounting;

use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasAuditTrail;
use App\Models\Concerns\HasUuid;
use App\Models\Core\Branch;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class JournalEntry extends Model
{
    use HasUuid;
    use BelongsToOrganization;
    use BelongsToBranch;
    use HasAuditTrail;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_POSTED = 'posted';
    public const STATUS_VOIDED = 'voided';

    protected $fillable = [
        'organization_id',
        'branch_id',
        'fiscal_year_id',
        'entry_number',
        'entry_date',
        'reference',
        'description',
        'source_type',
        'source_id',
        'currency_code',
        'exchange_rate',
        'total_debit',
        'total_credit',
        'status',
        'posted_at',
        'posted_by',
        'voided_at',
        'voided_by',
        'void_reason',
        'reversed_by_id',
        'reversal_of_id',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'entry_date' => 'date',
            'exchange_rate' => 'decimal:8',
            'total_debit' => 'decimal:4',
            'total_credit' => 'decimal:4',
            'posted_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (JournalEntry $entry) {
            if (!$entry->entry_number) {
                $entry->entry_number = static::generateEntryNumber($entry->organization_id);
            }

            if (!$entry->created_by) {
                $entry->created_by = auth()->id();
            }
        });
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function fiscalYear(): BelongsTo
    {
        return $this->belongsTo(FiscalYear::class);
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'currency_code', 'code');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalEntryLine::class)->orderBy('line_order');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function voidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    public function source(): MorphTo
    {
        return $this->morphTo('source', 'source_type', 'source_id');
    }

    public function reversedBy(): BelongsTo
    {
        return $this->belongsTo(static::class, 'reversed_by_id');
    }

    public function reversalOf(): BelongsTo
    {
        return $this->belongsTo(static::class, 'reversal_of_id');
    }

    /**
     * Check if the journal entry is balanced (debits = credits).
     */
    public function isBalanced(): bool
    {
        return bccomp((string) $this->total_debit, (string) $this->total_credit, 4) === 0;
    }

    /**
     * Recalculate totals from lines.
     */
    public function recalculateTotals(): void
    {
        $this->total_debit = $this->lines()->sum('debit');
        $this->total_credit = $this->lines()->sum('credit');
        $this->saveQuietly();
    }

    /**
     * Post the journal entry.
     */
    public function post(?int $userId = null): bool
    {
        if ($this->status !== self::STATUS_DRAFT) {
            return false;
        }

        if (!$this->isBalanced()) {
            return false;
        }

        // Check if fiscal year is open
        if ($this->fiscalYear->is_closed) {
            return false;
        }

        $this->update([
            'status' => self::STATUS_POSTED,
            'posted_at' => now(),
            'posted_by' => $userId ?? auth()->id(),
        ]);

        return true;
    }

    /**
     * Void the journal entry.
     */
    public function void(string $reason, ?int $userId = null): bool
    {
        if ($this->status !== self::STATUS_POSTED) {
            return false;
        }

        $this->update([
            'status' => self::STATUS_VOIDED,
            'voided_at' => now(),
            'voided_by' => $userId ?? auth()->id(),
            'void_reason' => $reason,
        ]);

        return true;
    }

    /**
     * Create a reversal entry.
     */
    public function reverse(string $reason, ?int $userId = null): ?JournalEntry
    {
        if ($this->status !== self::STATUS_POSTED || $this->reversed_by_id) {
            return null;
        }

        $reversal = static::create([
            'organization_id' => $this->organization_id,
            'branch_id' => $this->branch_id,
            'fiscal_year_id' => $this->fiscal_year_id,
            'entry_date' => now()->toDateString(),
            'reference' => "REV-{$this->entry_number}",
            'description' => "Reversal of {$this->entry_number}: {$reason}",
            'source_type' => $this->source_type,
            'source_id' => $this->source_id,
            'currency_code' => $this->currency_code,
            'exchange_rate' => $this->exchange_rate,
            'reversal_of_id' => $this->id,
            'created_by' => $userId ?? auth()->id(),
        ]);

        // Create reversed lines (swap debit/credit)
        foreach ($this->lines as $line) {
            $reversal->lines()->create([
                'account_id' => $line->account_id,
                'description' => $line->description,
                'debit' => $line->credit, // Swap
                'credit' => $line->debit, // Swap
                'base_debit' => $line->base_credit,
                'base_credit' => $line->base_debit,
                'cost_center_id' => $line->cost_center_id,
                'contact_id' => $line->contact_id,
                'line_order' => $line->line_order,
            ]);
        }

        $reversal->recalculateTotals();
        $reversal->post($userId);

        // Update original entry
        $this->update(['reversed_by_id' => $reversal->id]);

        return $reversal;
    }

    /**
     * Generate next entry number.
     */
    public static function generateEntryNumber(int $organizationId): string
    {
        $year = now()->format('Y');
        $prefix = "JE-{$year}-";

        $lastNumber = static::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->where('entry_number', 'like', "{$prefix}%")
            ->orderByRaw('CAST(SUBSTRING(entry_number, ?) AS UNSIGNED) DESC', [strlen($prefix) + 1])
            ->value('entry_number');

        if ($lastNumber) {
            $sequence = (int) substr($lastNumber, strlen($prefix)) + 1;
        } else {
            $sequence = 1;
        }

        return $prefix . str_pad((string) $sequence, 6, '0', STR_PAD_LEFT);
    }

    public function scopeDraft($query)
    {
        return $query->where('status', self::STATUS_DRAFT);
    }

    public function scopePosted($query)
    {
        return $query->where('status', self::STATUS_POSTED);
    }

    public function scopeForPeriod($query, string $startDate, string $endDate)
    {
        return $query->whereBetween('entry_date', [$startDate, $endDate]);
    }
}
