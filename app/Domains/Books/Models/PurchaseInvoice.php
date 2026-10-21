<?php

declare(strict_types=1);

namespace App\Domains\Books\Models;

use App\Domains\Organization\Concerns\BelongsToTenant;
use App\Domains\Organization\Concerns\RecordsActors;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A supplier's invoice or credit note the organization received, with its
 * lines. Written by Purchases only: recorded, then void if entered by
 * mistake - never changed otherwise, never deleted.
 *
 * Amounts are kept positive; a credit note takes away by its type (sign()).
 *
 * @property string $id
 * @property string $org_id
 * @property string $supplier_id
 * @property string $document_type
 * @property string|null $original_id
 * @property string $supplier_invoice_number
 * @property Carbon $invoice_date
 * @property Carbon $received_on
 * @property Carbon|null $due_on
 * @property bool $paid_later
 * @property Carbon|null $written_off_on
 * @property string $currency
 * @property string|null $exchange_rate
 * @property string $net
 * @property string $vat
 * @property string $gross
 * @property string $net_tax
 * @property string $vat_tax
 * @property string $gross_tax
 * @property string $deductible_share
 * @property string $deductible_vat_tax
 * @property string|null $description
 * @property string|null $file_path
 * @property string|null $file_name
 * @property string|null $file_mime
 * @property string $status
 * @property string|null $void_reason
 * @property Carbon|null $voided_at
 */
class PurchaseInvoice extends Model
{
    use BelongsToTenant;
    use HasUuids;
    use RecordsActors;

    public const INVOICE = 'invoice';

    public const CREDIT_NOTE = 'credit_note';

    public const RECORDED = 'recorded';

    public const VOID = 'void';

    protected $fillable = [
        'org_id',
        'supplier_id',
        'document_type',
        'original_id',
        'supplier_invoice_number',
        'invoice_date',
        // The day it was received: its input VAT is that period's.
        'received_on',
        // The agreed date for payment, and whether it will be paid later
        // than six months after it (PurchaseLedger).
        'due_on',
        'paid_later',
        'currency',
        'exchange_rate',
        'net',
        'vat',
        'gross',
        'net_tax',
        'vat_tax',
        'gross_tax',
        'deductible_share',
        'deductible_vat_tax',
        'description',
    ];

    /**
     * A financial record: who recorded it and how, who last touched it -
     * voiding it - and voided_by, set by Purchases.
     *
     * @return array<string, string>
     */
    public function attributionColumns(): array
    {
        return ['created_by' => 'created_by', 'updated_by' => 'updated_by', 'created_via' => 'created_via'];
    }

    protected function casts(): array
    {
        return [
            'invoice_date' => 'date',
            'received_on' => 'date',
            'due_on' => 'date',
            'paid_later' => 'boolean',
            'written_off_on' => 'date',
            'exchange_rate' => 'decimal:6',
            'net' => 'decimal:2',
            'vat' => 'decimal:2',
            'gross' => 'decimal:2',
            'net_tax' => 'decimal:2',
            'vat_tax' => 'decimal:2',
            'gross_tax' => 'decimal:2',
            'deductible_share' => 'decimal:2',
            'deductible_vat_tax' => 'decimal:2',
            'voided_at' => 'datetime',
            'file_size' => 'integer',
        ];
    }

    /** @return BelongsTo<Supplier, $this> */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class)->withTrashed();
    }

    /** @return BelongsTo<PurchaseInvoice, $this> */
    public function original(): BelongsTo
    {
        return $this->belongsTo(self::class, 'original_id');
    }

    /** @return HasMany<PurchaseLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseLine::class)->orderBy('position');
    }

    /** -1 for a credit note, which takes away from what the supplier billed. */
    public function sign(): int
    {
        return $this->document_type === self::CREDIT_NOTE ? -1 : 1;
    }

    public function isVoid(): bool
    {
        return $this->status === self::VOID;
    }

    public function documentLabel(): string
    {
        return $this->document_type === self::CREDIT_NOTE ? __('Credit note') : __('Invoice');
    }
}
