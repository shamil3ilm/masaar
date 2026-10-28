<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Models;

use App\Domains\Invoice\Enums\InvoiceStatus;
use App\Domains\Invoice\Exceptions\InvoiceNotEditableException;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A charge on an invoice - delivery, a service charge, packaging - on the
 * whole of it (BG-21) or, with invoice_line_id, on one line (BG-28).
 *
 * amount is as entered; net_amount is what the document states. A document
 * charge is made at its own tax category and rate; a line charge is taxed as
 * its line is. Part of the document, so frozen with it.
 */
class InvoiceCharge extends Model
{
    use HasUuids;

    protected $fillable = [
        'invoice_id',
        'invoice_line_id',
        'position',
        'reason',
        'reason_code',
        'percent',
        'base_amount',
        'amount',
        'net_amount',
        'tax_category',
        'tax_rate',
        'exempt_code',
        'exempt_reason',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'percent' => 'decimal:2',
            'base_amount' => 'decimal:2',
            'amount' => 'decimal:2',
            'net_amount' => 'decimal:2',
            'tax_rate' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        $guard = function (InvoiceCharge $charge): void {
            $invoice = Invoice::acrossTenants()->find($charge->invoice_id);

            if ($invoice !== null && $invoice->status !== InvoiceStatus::Draft) {
                throw InvoiceNotEditableException::for($invoice);
            }
        };

        static::creating($guard);
        static::updating($guard);
        static::deleting($guard);
    }
}
