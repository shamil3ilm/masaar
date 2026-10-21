<?php

declare(strict_types=1);

namespace App\Domains\Books\Models;

use App\Domains\Organization\Concerns\BelongsToTenant;
use App\Domains\Organization\Concerns\RecordsActors;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Money paid to a supplier against one purchase invoice, in its currency.
 * Written by PurchasePayments only, and never changed: a mistake is
 * answered by a reversal row below zero.
 *
 * @property string $id
 * @property string $org_id
 * @property string $purchase_invoice_id
 * @property string $amount
 * @property Carbon $paid_on
 * @property string|null $reference
 * @property string|null $reverses_id
 */
class PurchasePayment extends Model
{
    use BelongsToTenant;
    use HasUuids;
    use RecordsActors;

    public const UPDATED_AT = null;

    protected $fillable = [
        'org_id',
        'purchase_invoice_id',
        'amount',
        'paid_on',
        'reference',
        'reverses_id',
    ];

    /**
     * A financial record: who recorded it and how, never who changed it.
     *
     * @return array<string, string>
     */
    public function attributionColumns(): array
    {
        return ['created_by' => 'created_by', 'created_via' => 'created_via'];
    }

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'paid_on' => 'date'];
    }

    public function isReversal(): bool
    {
        return $this->reverses_id !== null;
    }
}
