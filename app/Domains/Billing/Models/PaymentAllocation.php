<?php

declare(strict_types=1);

namespace App\Domains\Billing\Models;

use App\Domains\Organization\Concerns\BelongsToTenant;
use App\Domains\Organization\Concerns\RecordsActors;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Part of a payment, or of a credit, set against one invoice. Never
 * changed: a mistake is answered with a refund or a credit, not an edit.
 */
class PaymentAllocation extends Model
{
    use BelongsToTenant;
    use HasUuids;
    use RecordsActors;

    public const UPDATED_AT = null;

    protected $fillable = ['org_id', 'receivable_id', 'payment_id', 'credit_id', 'amount', 'fx_difference', 'allocated_on', 'reverses_id'];

    /** @return array<string, string> */
    public function attributionColumns(): array
    {
        return ['created_by' => 'created_by'];
    }

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'fx_difference' => 'decimal:2',
            'allocated_on' => 'date',
        ];
    }

    /** @return BelongsTo<Receivable, $this> */
    public function receivable(): BelongsTo
    {
        return $this->belongsTo(Receivable::class);
    }

    /** @return BelongsTo<Payment, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /** @return BelongsTo<BillingCredit, $this> */
    public function credit(): BelongsTo
    {
        return $this->belongsTo(BillingCredit::class, 'credit_id');
    }
}
