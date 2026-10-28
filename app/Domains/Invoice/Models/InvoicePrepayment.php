<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Models;

use App\Domains\Invoice\Enums\InvoiceStatus;
use App\Domains\Invoice\Exceptions\InvoiceNotEditableException;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A prepayment a final invoice settles, at one tax category: the
 * prepayment invoice, and what was prepaid at that category with its VAT.
 * Part of the final document, so frozen with it.
 */
class InvoicePrepayment extends Model
{
    use HasUuids;

    protected $fillable = [
        'invoice_id',
        'prepayment_invoice_id',
        'position',
        'invoice_number',
        'issue_date',
        'issued_at',
        'tax_category',
        'tax_rate',
        'exempt_code',
        'exempt_reason',
        'taxable_amount',
        'tax_amount',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'issue_date' => 'date',
            'issued_at' => 'datetime',
            'tax_rate' => 'decimal:2',
            'taxable_amount' => 'decimal:2',
            'tax_amount' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        $guard = function (InvoicePrepayment $prepayment): void {
            $invoice = Invoice::acrossTenants()->find($prepayment->invoice_id);

            if ($invoice !== null && $invoice->status !== InvoiceStatus::Draft) {
                throw InvoiceNotEditableException::for($invoice);
            }
        };

        static::creating($guard);
        static::updating($guard);
        static::deleting($guard);
    }

    /** The final invoice settling it. */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /** The prepayment invoice settled. */
    public function prepaymentInvoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'prepayment_invoice_id');
    }
}
