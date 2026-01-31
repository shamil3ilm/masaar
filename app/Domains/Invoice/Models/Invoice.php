<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Models;

use App\Domains\Compliance\Zatca\Models\InvoiceSubmission;
use App\Domains\Invoice\Enums\DocumentType;
use App\Domains\Invoice\Enums\InvoiceStatus;
use App\Domains\Invoice\Enums\InvoiceType;
use App\Domains\Organization\Models\Organization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

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
        'document_type',
        'status',
        'issue_date',
        'supply_date',
        'currency',
        'buyer_name',
        'buyer_vat_number',
        'buyer_address',
        'payment_means_code',
        'billing_reference_id',
        'adjustment_reason',
        'subtotal',
        'discount_amount',
        'tax_amount',
        'total',
        'hash',
        'qr_code',
        'signed_xml',
        'icv',
        'zatca_response',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'type' => InvoiceType::class,
            'document_type' => DocumentType::class,
            'status' => InvoiceStatus::class,
            'issue_date' => 'date',
            'supply_date' => 'date',
            'subtotal' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'total' => 'decimal:2',
            'icv' => 'integer',
            'zatca_response' => 'array',
            'buyer_address' => 'array',
        ];
    }

    /**
     * Boot method to auto-generate ICV.
     */
    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (Invoice $invoice) {
            if ($invoice->icv === null && $invoice->organization_id) {
                $invoice->icv = static::generateNextIcv($invoice->organization_id);
            }
        });
    }

    /**
     * Generate next ICV for organization.
     * Uses database lock to ensure sequential uniqueness.
     */
    public static function generateNextIcv(string $organizationId): int
    {
        return DB::transaction(function () use ($organizationId) {
            $maxIcv = static::where('organization_id', $organizationId)
                ->lockForUpdate()
                ->max('icv');

            return ($maxIcv ?? 0) + 1;
        });
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

    /**
     * Check if invoice is B2B (Standard - requires clearance).
     */
    public function isB2B(): bool
    {
        return $this->type === InvoiceType::Standard;
    }

    /**
     * Check if invoice is B2C (Simplified - reporting only).
     */
    public function isB2C(): bool
    {
        return $this->type === InvoiceType::Simplified;
    }

    /**
     * Get invoice submissions.
     */
    public function submissions(): HasMany
    {
        return $this->hasMany(InvoiceSubmission::class);
    }

    /**
     * Get the latest submission.
     */
    public function latestSubmission()
    {
        return $this->hasOne(InvoiceSubmission::class)->latestOfMany();
    }

    /**
     * Check if invoice has been successfully submitted.
     */
    public function isSubmitted(): bool
    {
        return $this->submissions()
            ->whereIn('state', ['cleared', 'reported', 'warning'])
            ->exists();
    }

    /**
     * Check if invoice has pending submission.
     */
    public function hasPendingSubmission(): bool
    {
        return $this->submissions()
            ->whereIn('state', ['queued', 'pending_submission', 'submitted'])
            ->exists();
    }
}
