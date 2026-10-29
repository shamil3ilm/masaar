<?php

declare(strict_types=1);

namespace App\Domains\Books\Models;

use App\Domains\Organization\Concerns\BelongsToTenant;
use App\Domains\Organization\Concerns\RecordsActors;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Output tax taken back on an unpaid sale (relief), given back when it is
 * paid after all (recovery), and taken back again when that payment is
 * reversed (recovery_reversed). Written by SalesBadDebts only; never changed.
 *
 * @property string $id
 * @property string $org_id
 * @property string $invoice_id
 * @property string $kind
 * @property string|null $relief_id
 * @property string|null $invoice_payment_id
 * @property bool $by_credit_note
 * @property Carbon $happened_on
 * @property string $amount
 * @property Carbon|null $written_off_on
 * @property Carbon|null $notified_on
 * @property string|null $certificate_reference
 * @property string|null $evidence_reference
 * @property bool $unrelated_confirmed
 */
class BadDebtRelief extends Model
{
    use BelongsToTenant;
    use HasUuids;
    use RecordsActors;

    public const UPDATED_AT = null;

    public const RELIEF = 'relief';

    public const RECOVERY = 'recovery';

    /** A recovery undone: the payment it came from was reversed, so the amount stands written off again. */
    public const REVERSED = 'recovery_reversed';

    protected $fillable = [
        'org_id', 'invoice_id', 'kind', 'relief_id', 'invoice_payment_id', 'happened_on', 'amount', 'written_off_on',
        'notified_on', 'certificate_reference', 'evidence_reference', 'unrelated_confirmed', 'by_credit_note',
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

    /** The amount as it counts: what is written off above zero, what came back below. */
    public function signed(): float
    {
        return ($this->kind === self::RECOVERY ? -1 : 1) * (float) $this->amount;
    }

    protected function casts(): array
    {
        return [
            'happened_on' => 'date',
            'amount' => 'decimal:2',
            'written_off_on' => 'date',
            'notified_on' => 'date',
            'unrelated_confirmed' => 'boolean',
            'by_credit_note' => 'boolean',
        ];
    }
}
