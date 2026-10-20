<?php

declare(strict_types=1);

namespace App\Domains\Referral\Models;

use App\Domains\Organization\Models\Organization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An organization's referral code: in its link, and typed at sign-up.
 * Made by the platform the first time it is asked for (ReferralCodes).
 *
 * @property string $org_id
 * @property string $code
 */
class ReferralCode extends Model
{
    use HasUuids;

    protected $fillable = ['org_id', 'code'];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'org_id');
    }
}
