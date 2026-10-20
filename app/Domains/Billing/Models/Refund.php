<?php

declare(strict_types=1);

namespace App\Domains\Billing\Models;

use App\Domains\Organization\Concerns\BelongsToTenant;
use App\Domains\Organization\Concerns\RecordsActors;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Money given back to a customer: a credit note against an invoice and
 * what of it was kept to the customer's credit or paid back, or a credit
 * paid back on its own (Refunds). Written once.
 */
class Refund extends Model
{
    use BelongsToTenant;
    use HasUuids;
    use RecordsActors;

    public const UPDATED_AT = null;

    /** Kept to the customer's credit, for its next invoices. */
    public const TO_CREDIT = 'credit';

    protected $table = 'billing_refunds';

    protected $fillable = [
        'org_id', 'receivable_id', 'credit_note_id', 'credit_id', 'currency', 'credited_amount', 'payout_amount',
        'payout_method', 'reference', 'gateway_reference', 'reason', 'refunded_on',
    ];

    /** @return array<string, string> */
    public function attributionColumns(): array
    {
        return ['created_by' => 'created_by', 'created_via' => 'created_via'];
    }

    protected function casts(): array
    {
        return [
            'credited_amount' => 'decimal:2',
            'payout_amount' => 'decimal:2',
            'refunded_on' => 'date',
        ];
    }

    /** @return BelongsTo<Receivable, $this> */
    public function receivable(): BelongsTo
    {
        return $this->belongsTo(Receivable::class);
    }
}
