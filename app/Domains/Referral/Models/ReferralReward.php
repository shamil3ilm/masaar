<?php

declare(strict_types=1);

namespace App\Domains\Referral\Models;

use App\Domains\Organization\Concerns\RecordsActors;
use App\Domains\Organization\Models\Organization;
use App\Domains\Referral\Enums\RewardKind;
use App\Domains\Referral\Enums\RewardStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What one side of a referral was given: a credit (its account credit), or
 * months on its plan (its licence), or withheld until an admin grants it.
 * Never deleted: a reversal keeps who and why.
 *
 * @property string $referral_id
 * @property string $org_id
 * @property string $side
 * @property RewardKind $kind
 * @property string|null $percent
 * @property string|null $amount
 * @property string|null $currency
 * @property int|null $months
 * @property RewardStatus $status
 * @property string|null $withheld_reason
 * @property string|null $credit_id
 * @property string|null $licence_id
 */
class ReferralReward extends Model
{
    use HasUuids;
    use RecordsActors;

    public const REFERRER = 'referrer';

    public const REFERRED = 'referred';

    protected $fillable = [
        'referral_id',
        'org_id',
        'side',
        'kind',
        'percent',
        'amount',
        'currency',
        'months',
        'status',
        'withheld_reason',
    ];

    /**
     * A financial record: who granted it; its reversal names its own actor.
     *
     * @return array<string, string>
     */
    public function attributionColumns(): array
    {
        return ['created_by' => 'created_by'];
    }

    protected function casts(): array
    {
        return [
            'kind' => RewardKind::class,
            'status' => RewardStatus::class,
            'percent' => 'decimal:2',
            'amount' => 'decimal:2',
            'months' => 'integer',
            'granted_at' => 'datetime',
            'reversed_at' => 'datetime',
        ];
    }

    /** What it is, as a person reads it. */
    public function describe(): string
    {
        return match ($this->kind) {
            RewardKind::PercentOff => __(':percent% off the next invoice', ['percent' => (int) $this->percent]),
            RewardKind::Credit => __('A credit of :amounts', ['amounts' => number_format((float) $this->amount, 2).' '.$this->currency]),
            RewardKind::FreeMonths => (int) $this->months === 1 ? __('One free month') : __(':count free months', ['count' => (int) $this->months]),
            RewardKind::None => __('Nothing'),
        };
    }

    public function referral(): BelongsTo
    {
        return $this->belongsTo(Referral::class, 'referral_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'org_id');
    }
}
