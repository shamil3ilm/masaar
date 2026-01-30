<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Models;

use App\Domains\Invoice\Enums\InvoiceStatus;
use App\Domains\Invoice\Enums\InvoiceType;
use App\Domains\Organization\Models\Organization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Invoice aggregate root.
 *
 * Core business entity for ZATCA compliance.
 * Immutable after status changes from Draft.
 */
class Invoice extends Model
{
    use HasUuids;

    protected $fillable = [
        'organization_id',
        'invoice_number',
        'type',
        'status',
        'issue_date',
        'supply_date',
        'currency',
        'buyer_name',
        'buyer_vat_number',
        'buyer_address',
        'subtotal',
        'tax_amount',
        'total',
        'hash',
        'qr_code',
        'zatca_response',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'type' => InvoiceType::class,
            'status' => InvoiceStatus::class,
            'issue_date' => 'date',
            'supply_date' => 'date',
            'subtotal' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'total' => 'decimal:2',
            'zatca_response' => 'array',
        ];
    }

    /**
     * Organization that owns this invoice.
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Invoice line items.
     */
    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class);
    }

    /**
     * Check if invoice can be edited.
     */
    public function isEditable(): bool
    {
        return $this->status->isEditable();
    }

    /**
     * Check if invoice requires ZATCA clearance.
     */
    public function requiresClearance(): bool
    {
        return $this->type->requiresClearance();
    }
}
