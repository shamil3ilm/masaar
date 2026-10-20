<?php

declare(strict_types=1);

namespace App\Domains\Referral\Models;

use App\Domains\Organization\Concerns\RecordsActors;
use App\Domains\Organization\Models\Organization;
use App\Domains\Referral\DTOs\ReferralTerms;
use App\Domains\Referral\Enums\ReferralStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A company that signed up with another's referral code, and the terms its
 * referrer had then.
 *
 * Not tenant-scoped: two organizations are party to it, and Referrals names
 * which in every query.
 *
 * @property string $referrer_org_id
 * @property string $org_id
 * @property string $code
 * @property ReferralStatus $status
 * @property array<string, mixed> $terms
 * @property string|null $refusal_reason
 */
class Referral extends Model
{
    use HasUuids;
    use RecordsActors;

    protected $fillable = [
        'referrer_org_id',
        'org_id',
        'code',
        'status',
        'terms',
        'refusal_reason',
    ];

    /**
     * @return array<string, string>
     */
    public function attributionColumns(): array
    {
        return ['created_by' => 'created_by', 'updated_by' => 'updated_by'];
    }

    protected function casts(): array
    {
        return [
            'status' => ReferralStatus::class,
            'terms' => 'array',
            'approved_at' => 'datetime',
            'qualified_at' => 'datetime',
            'rewarded_at' => 'datetime',
            'reversed_at' => 'datetime',
            'review_flagged_at' => 'datetime',
        ];
    }

    /** The terms it signed up under. */
    public function agreedTerms(): ReferralTerms
    {
        return ReferralTerms::fromArray((array) $this->terms);
    }

    public function referrer(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'referrer_org_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'org_id');
    }

    public function rewards(): HasMany
    {
        return $this->hasMany(ReferralReward::class, 'referral_id')->orderBy('created_at');
    }
}
