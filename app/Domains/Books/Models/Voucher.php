<?php

declare(strict_types=1);

namespace App\Domains\Books\Models;

use App\Domains\Organization\Concerns\BelongsToTenant;
use App\Domains\Organization\Concerns\RecordsActors;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A voucher sold: redeemable for its face value until it expires. Selling it
 * is no supply and makes no invoice; its balance is its face value less its
 * redemptions (Vouchers). Never changed after it is sold.
 *
 * @property string $id
 * @property string $org_id
 * @property string $code
 * @property string $face_value
 * @property string $currency
 * @property Carbon $sold_on
 * @property Carbon|null $expires_on
 * @property string|null $customer_id
 * @property string|null $holder_name
 * @property string $payment_method
 * @property string|null $reference
 */
class Voucher extends Model
{
    use BelongsToTenant;
    use HasUuids;
    use RecordsActors;

    public const UPDATED_AT = null;

    protected $fillable = [
        'org_id', 'branch_id', 'code', 'face_value', 'currency', 'sold_on', 'expires_on', 'customer_id', 'holder_name', 'payment_method', 'reference',
    ];

    protected function casts(): array
    {
        return [
            'face_value' => 'decimal:2',
            'sold_on' => 'date',
            'expires_on' => 'date',
        ];
    }

    /**
     * A financial record: who sold it, how it arrived and under which licence.
     *
     * @return array<string, string>
     */
    public function attributionColumns(): array
    {
        return ['created_by' => 'created_by', 'created_via' => 'created_via', 'license_id' => 'license_id'];
    }

    /** @return HasMany<VoucherRedemption, $this> */
    public function redemptions(): HasMany
    {
        return $this->hasMany(VoucherRedemption::class)->orderBy('redeemed_on')->orderBy('created_at');
    }
}
