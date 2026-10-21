<?php

declare(strict_types=1);

namespace App\Domains\Billing\Models;

use App\Domains\Organization\Concerns\BelongsToTenant;
use App\Domains\Organization\Concerns\RecordsActors;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A refund on its way, written before its credit note is issued or its
 * money sent, and moved on step by step until the Refund it becomes is
 * recorded (Refunds). Never deleted.
 */
class RefundIntent extends Model
{
    use BelongsToTenant;
    use HasUuids;
    use RecordsActors;

    /** Held against the invoice; the credit note is being issued. */
    public const RESERVED = 'reserved';

    /** The credit note is issued; the invoice is not credited yet. */
    public const NOTED = 'noted';

    /** Credited; the money is going back to the card. */
    public const PAYING = 'paying';

    public const DONE = 'done';

    public const FAILED = 'failed';

    /** Still to finish: what a retry carries on. */
    public const OPEN = [self::NOTED, self::PAYING];

    protected $table = 'billing_refund_intents';

    protected $fillable = [
        'org_id', 'receivable_id', 'credit_id', 'currency', 'credited_amount', 'payout_method', 'payout_amount',
        'payment_id', 'reference', 'reason', 'status', 'credit_note_id', 'refund_id', 'error',
    ];

    /** @return array<string, string> */
    public function attributionColumns(): array
    {
        return ['created_by' => 'created_by'];
    }

    protected function casts(): array
    {
        return [
            'credited_amount' => 'decimal:2',
            'payout_amount' => 'decimal:2',
        ];
    }
}
