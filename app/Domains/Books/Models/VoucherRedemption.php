<?php

declare(strict_types=1);

namespace App\Domains\Books\Models;

use App\Domains\Organization\Concerns\RecordsActors;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A voucher used to pay an issued invoice, recorded as that invoice's
 * customer payment. Never changed.
 *
 * @property string $id
 * @property string $voucher_id
 * @property string $invoice_id
 * @property string $invoice_payment_id
 * @property string $amount
 * @property Carbon $redeemed_on
 */
class VoucherRedemption extends Model
{
    use HasUuids;
    use RecordsActors;

    public const UPDATED_AT = null;

    protected $fillable = ['voucher_id', 'invoice_id', 'invoice_payment_id', 'amount', 'redeemed_on'];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'redeemed_on' => 'date',
        ];
    }

    /** @return array<string, string> */
    public function attributionColumns(): array
    {
        return ['created_by' => 'created_by'];
    }
}
