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
 * Money a customer paid against one of the organization's issued invoices,
 * in the invoice's currency. Written by CustomerPayments only, and never
 * changed: a mistake is answered by a reversal row below zero.
 *
 * @property string $id
 * @property string $org_id
 * @property string $invoice_id
 * @property string $amount
 * @property string $currency
 * @property Carbon $paid_on
 * @property PaymentMethod $method
 * @property string|null $reference
 * @property string|null $notes
 * @property string|null $reverses_id
 */
class InvoicePayment extends Model
{
    use BelongsToTenant;
    use HasUuids;
    use RecordsActors;

    public const UPDATED_AT = null;

    protected $fillable = [
        'org_id',
        'invoice_id',
        'amount',
        'currency',
        'paid_on',
        'method',
        'reference',
        'notes',
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
        return [
            'amount' => 'decimal:2',
            'paid_on' => 'date',
            'method' => PaymentMethod::class,
        ];
    }

    public function isReversal(): bool
    {
        return $this->reverses_id !== null;
    }
}
