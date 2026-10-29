<?php

declare(strict_types=1);

namespace App\Domains\Books\Models;

use App\Domains\Books\Enums\PaymentMethod;
use App\Domains\Organization\Concerns\BelongsToTenant;
use App\Domains\Organization\Concerns\RecordsActors;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Money a customer paid on account, not yet set against an invoice - or a
 * refund of what is left of it, or the reversal of either. Written by
 * CustomerPayments only, and never changed.
 *
 * @property string $id
 * @property string $org_id
 * @property string|null $branch_id
 * @property string $customer_id
 * @property string $kind
 * @property string|null $receipt_id
 * @property string|null $reverses_id
 * @property string $amount
 * @property string $currency
 * @property Carbon $received_on
 * @property PaymentMethod $method
 * @property string|null $reference
 * @property string|null $notes
 */
class CustomerReceipt extends Model
{
    use BelongsToTenant;
    use HasUuids;
    use RecordsActors;

    public const UPDATED_AT = null;

    /** Money received on account. */
    public const RECEIPT = 'receipt';

    /** What was left of a receipt, paid back to the customer: below zero. */
    public const REFUND = 'refund';

    /** A receipt or refund recorded in error, taken back. */
    public const REVERSAL = 'reversal';

    protected $fillable = [
        'org_id', 'branch_id', 'customer_id', 'kind', 'receipt_id', 'reverses_id', 'amount', 'currency',
        'received_on', 'method', 'reference', 'notes',
    ];

    /**
     * A financial record: who recorded it and how.
     *
     * @return array<string, string>
     */
    public function attributionColumns(): array
    {
        return ['created_by' => 'created_by', 'created_via' => 'created_via'];
    }

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'received_on' => 'date',
            'method' => PaymentMethod::class,
        ];
    }
}
