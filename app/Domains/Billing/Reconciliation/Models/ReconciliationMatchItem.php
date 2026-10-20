<?php

declare(strict_types=1);

namespace App\Domains\Billing\Reconciliation\Models;

use App\Domains\Billing\Models\Payment;
use App\Domains\Billing\Models\Receivable;
use App\Domains\Billing\Models\Refund;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One thing a match joins: a statement line, an invoice and how much of
 * it, a payment already recorded, or the payment the match recorded for a
 * line. Written once.
 */
class ReconciliationMatchItem extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    public const LINE = 'line';

    public const RECEIVABLE = 'receivable';

    public const PAYMENT = 'payment';

    public const CREATED = 'created';

    public const REFUND = 'refund';

    protected $fillable = ['match_id', 'role', 'statement_line_id', 'receivable_id', 'payment_id', 'refund_id', 'amount'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }

    /** @return BelongsTo<ReconciliationMatch, $this> */
    public function reconciliationMatch(): BelongsTo
    {
        return $this->belongsTo(ReconciliationMatch::class, 'match_id');
    }

    /** @return BelongsTo<StatementLine, $this> */
    public function line(): BelongsTo
    {
        return $this->belongsTo(StatementLine::class, 'statement_line_id');
    }

    /** @return BelongsTo<Receivable, $this> */
    public function receivable(): BelongsTo
    {
        return $this->belongsTo(Receivable::class);
    }

    /** @return BelongsTo<Refund, $this> */
    public function refund(): BelongsTo
    {
        return $this->belongsTo(Refund::class);
    }

    /** @return BelongsTo<Payment, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
