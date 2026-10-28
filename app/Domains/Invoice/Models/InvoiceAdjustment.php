<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Models;

use App\Domains\Invoice\Enums\AdjustmentKind;
use App\Domains\Organization\Concerns\BelongsToTenant;
use App\Domains\Organization\Concerns\RecordsActors;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An event on an issued invoice and the documents made for it: a return,
 * an exchange, a discount, a replacement, a buyer's details corrected, a
 * payment after bad-debt relief (AdjustmentKind). Written by
 * InvoiceAdjustments and SalesBadDebts only; never changed.
 *
 * @property string $id
 * @property string $org_id
 * @property string|null $branch_id
 * @property string $invoice_id
 * @property AdjustmentKind $kind
 * @property string|null $reason
 * @property array<string, mixed>|null $details
 * @property string|null $note_id
 * @property string|null $new_invoice_id
 * @property string|null $bad_debt_relief_id
 * @property Carbon $created_at
 */
class InvoiceAdjustment extends Model
{
    use BelongsToTenant;
    use HasUuids;
    use RecordsActors;

    public const UPDATED_AT = null;

    protected $fillable = [
        'org_id', 'branch_id', 'invoice_id', 'kind', 'reason', 'details', 'note_id', 'new_invoice_id', 'bad_debt_relief_id',
    ];

    /**
     * A compliance record: who recorded it, how and under which licence.
     *
     * @return array<string, string>
     */
    public function attributionColumns(): array
    {
        return ['created_by' => 'created_by', 'created_via' => 'created_via', 'license_id' => 'license_id'];
    }

    /** @return BelongsTo<Invoice, $this> */
    public function note(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'note_id');
    }

    /** @return BelongsTo<Invoice, $this> */
    public function newInvoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'new_invoice_id');
    }

    protected function casts(): array
    {
        return [
            'kind' => AdjustmentKind::class,
            'details' => 'array',
        ];
    }
}
