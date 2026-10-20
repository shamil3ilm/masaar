<?php

declare(strict_types=1);

namespace App\Domains\Billing\Reconciliation\Models;

use App\Domains\Billing\Reconciliation\MatchBasis;
use App\Domains\Billing\Reconciliation\MatchRule;
use App\Domains\Billing\Reconciliation\MatchStatus;
use App\Domains\Organization\Concerns\BelongsToTenant;
use App\Domains\Organization\Concerns\RecordsActors;
use App\Domains\Organization\Models\Organization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Statement lines matched to one customer's money, and where that stands.
 * Its items say what it joins; its status moves, each move with who and
 * when; it is never deleted.
 */
class ReconciliationMatch extends Model
{
    use BelongsToTenant;
    use HasUuids;
    use RecordsActors;

    protected $fillable = [
        'org_id',
        'status',
        'rule',
        'confidence',
        'basis',
        'currency',
        'lines_amount',
        'targets_amount',
        'difference',
        'fee_amount',
        'auto_confirmed',
    ];

    /** @return array<string, string> */
    public function attributionColumns(): array
    {
        return ['created_by' => 'created_by'];
    }

    protected function casts(): array
    {
        return [
            'status' => MatchStatus::class,
            'rule' => MatchRule::class,
            'confidence' => 'integer',
            'basis' => 'array',
            'lines_amount' => 'decimal:2',
            'targets_amount' => 'decimal:2',
            'difference' => 'decimal:2',
            'fee_amount' => 'decimal:2',
            'auto_confirmed' => 'boolean',
            'confirmed_at' => 'datetime',
            'rejected_at' => 'datetime',
            'reversed_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'org_id');
    }

    /** @return HasMany<ReconciliationMatchItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(ReconciliationMatchItem::class, 'match_id');
    }

    /**
     * Why it was suggested, in words.
     *
     * @return list<string>
     */
    public function reasons(): array
    {
        return array_map(fn ($code): string => MatchBasis::label((string) $code), array_values((array) $this->basis));
    }

    /** Short of what it was matched to: below zero. Over: above. */
    public function isShort(): bool
    {
        return (float) $this->difference + (float) $this->fee_amount < -0.004;
    }

    public function isOver(): bool
    {
        return (float) $this->difference > 0.004;
    }
}
