<?php

declare(strict_types=1);

namespace App\Domains\Billing\Models;

use App\Domains\Organization\Concerns\BelongsToTenant;
use App\Domains\Organization\Concerns\RecordsActors;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Money to a customer's credit, in one currency: an overpayment, what a
 * credit note left over, or an admin's adjustment (Credits) - money the
 * customer has with the platform, settled against invoices like a payment.
 * A referral reward is a discount instead (CreditSource), taken off the
 * next invoice before VAT.
 * Spent by allocations from it; never changed once granted.
 */
class BillingCredit extends Model
{
    use BelongsToTenant;
    use HasUuids;
    use RecordsActors;

    public const UPDATED_AT = null;

    /** A payment that came to more than the invoices it settled. */
    public const OVERPAYMENT = 'overpayment';

    /** A credit note that left more paid on an invoice than it now owes. */
    public const CREDIT_NOTE = 'credit_note';

    /** An admin's goodwill or correction. */
    public const ADJUSTMENT = 'adjustment';

    public const SOURCES = [self::OVERPAYMENT, self::CREDIT_NOTE, self::ADJUSTMENT];

    protected $fillable = ['org_id', 'currency', 'amount', 'source', 'source_id', 'reason', 'granted_on', 'expires_on'];

    /** @return array<string, string> */
    public function attributionColumns(): array
    {
        return ['created_by' => 'created_by', 'created_via' => 'created_via'];
    }

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'granted_on' => 'date',
            'expires_on' => 'date',
        ];
    }

    /** @return HasMany<PaymentAllocation, $this> */
    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class, 'credit_id');
    }
}
