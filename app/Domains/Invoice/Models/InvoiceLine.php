<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Models;

use App\Domains\Invoice\Enums\InvoiceStatus;
use App\Domains\Invoice\Exceptions\InvoiceNotEditableException;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Invoice line item.
 *
 * Represents a single product/service on an invoice.
 */
class InvoiceLine extends Model
{
    use HasUuids;

    protected $fillable = [
        'invoice_id',
        'description',
        'description_ar',
        // PINT AE's item name (IBT-153), apart from the description.
        'item_name',
        'class_code',
        'quantity',
        'unit_code',
        'unit_price',
        // The exact net. A net price backed out of a VAT-inclusive one is
        // rounded, so quantity times it is not always the line's net.
        'net_amount',
        'tax_rate',
        'tax_amount',
        'tax_category',
        'exempt_code',
        'exempt_reason',
        'line_total',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'unit_price' => 'decimal:2',
            'net_amount' => 'decimal:2',
            'tax_rate' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'line_total' => 'decimal:2',
        ];
    }

    /**
     * A line is part of the document, so it is frozen with its invoice.
     *
     * Invoice guarded its own columns and nothing guarded its lines: an issued
     * invoice's quantities, prices and VAT could be edited, added to or
     * deleted, and the stored document no longer matched what was signed and
     * filed.
     */
    protected static function booted(): void
    {
        $guard = function (InvoiceLine $line): void {
            $invoice = Invoice::withoutTenantScope(fn (): ?Invoice => Invoice::query()->find($line->invoice_id));

            if ($invoice !== null && $invoice->status !== InvoiceStatus::Draft) {
                throw InvoiceNotEditableException::for($invoice);
            }
        };

        static::creating($guard);
        static::updating($guard);
        static::deleting($guard);
    }

    /**
     * The unit price as it was entered: with VAT in it when the invoice was
     * priced that way, since that is the price the customer saw.
     *
     * unit_price is always net - what ZATCA is given - and on an invoice
     * priced VAT-inclusive that is not the price on the shelf.
     */
    public function priceAsEntered(bool $pricesIncludeVat): string
    {
        if (! $pricesIncludeVat || bccomp((string) $this->quantity, '0', 3) <= 0) {
            return (string) $this->unit_price;
        }

        return number_format((float) bcdiv((string) $this->line_total, (string) $this->quantity, 6), 2, '.', '');
    }

    /**
     * Parent invoice.
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * The line description this document carries, preferring Arabic.
     *
     * The Arabic requirement is about the fields a person reads, and a line
     * description is one of them.
     */
    public function documentDescription(): string
    {
        $arabic = trim((string) $this->description_ar);

        return $arabic !== '' ? $arabic : (string) $this->description;
    }
}
