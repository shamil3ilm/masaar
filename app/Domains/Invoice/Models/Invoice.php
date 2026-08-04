<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Models;

use App\Domains\Organization\Concerns\BelongsToTenant;
use App\Domains\Compliance\Fatoora\Models\InvoiceSubmission;
use App\Domains\Invoice\Enums\DocumentType;
use App\Domains\Invoice\Enums\InvoiceStatus;
use App\Domains\Invoice\Enums\InvoiceType;
use App\Domains\Organization\Models\ComplianceProfile;
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
    use BelongsToTenant;
    use HasUuids;

    protected $fillable = [
        'organization_id',
        'compliance_profile_id',
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
        'erp_reference_id',
        // ZATCA BT-3 invoice sub-type flags (bits 3-7)
        'is_third_party',
        'is_nominal',
        'is_export',
        'is_summary',
        'is_self_billed',
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
            'is_third_party' => 'boolean',
            'is_nominal'     => 'boolean',
            'is_export'      => 'boolean',
            'is_summary'     => 'boolean',
            'is_self_billed' => 'boolean',
        ];
    }

    /**
     * Immutable fields after invoice is finalized (status != draft).
     * These fields cannot be changed once invoice leaves draft status.
     */
    public const IMMUTABLE_FIELDS = [
        'organization_id',
        'invoice_number',
        'type',
        'document_type',
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
        'icv',
    ];

    /**
     * Fields that can be updated after finalization (ZATCA response data).
     */
    public const MUTABLE_AFTER_FINALIZED = [
        'status',
        'hash',
        'qr_code',
        'signed_xml',
        'zatca_response',
        'notes',
        'erp_reference_id',
        'updated_at',
    ];

    /**
     * Boot method for ICV generation and immutability enforcement.
     *
     * COMPLIANCE: Invoices are immutable after leaving Draft status.
     * This is a core ZATCA requirement - issued invoices cannot be modified.
     */
    protected static function boot(): void
    {
        parent::boot();

        // Auto-generate ICV on creation
        static::creating(function (Invoice $invoice) {
            if ($invoice->icv === null && $invoice->organization_id) {
                $invoice->icv = static::generateNextIcv($invoice->organization_id);
            }
        });

        // Prevent deletion of finalized invoices
        static::deleting(function (Invoice $invoice) {
            if ($invoice->status !== InvoiceStatus::Draft) {
                throw new \RuntimeException(
                    'Finalized invoices cannot be deleted. ' .
                    'This is a ZATCA compliance requirement. ' .
                    'Use credit/debit notes for corrections.'
                );
            }
        });

        // Enforce immutability on finalized invoices
        static::updating(function (Invoice $invoice) {
            $originalStatus = $invoice->getOriginal('status');

            // If invoice was/is in draft, allow all changes
            if ($originalStatus === InvoiceStatus::Draft || $originalStatus === null) {
                return;
            }

            // Invoice is finalized - check for immutable field changes
            $changedFields = array_keys($invoice->getDirty());
            $immutableChanges = array_intersect($changedFields, self::IMMUTABLE_FIELDS);

            if (!empty($immutableChanges)) {
                throw new \RuntimeException(
                    'Finalized invoice fields cannot be modified. ' .
                    'This is a ZATCA compliance requirement. ' .
                    'Attempted to change: ' . implode(', ', $immutableChanges) . '. ' .
                    'Use credit/debit notes for corrections.'
                );
            }
        });
    }

    /**
     * Allocate the next ICV for an organization.
     *
     * ZATCA requires the invoice counter to be strictly sequential per
     * taxpayer, and the PIH chain is built on it, so two invoices must never
     * receive the same value.
     *
     * The lock is taken on the organization row rather than on the invoice
     * rows. Locking `SELECT MAX(icv) ... FOR UPDATE` looks equivalent but has
     * a hole: for an organization's first invoice there are no invoice rows to
     * lock, so two concurrent requests both read no rows and both allocate 1.
     * The organization row always exists, so it serialises every case.
     *
     * Callers must create the invoice inside a transaction. Laravel turns this
     * nested transaction into a savepoint, which keeps the lock held until the
     * outer commit — that is, until after the INSERT. Without an outer
     * transaction the lock is released here and the window reopens.
     * `invoices_org_icv_unique` is the backstop either way: a collision fails
     * the insert rather than corrupting the chain.
     */
    public static function generateNextIcv(string $organizationId): int
    {
        return DB::transaction(function () use ($organizationId) {
            DB::table('organizations')
                ->where('id', $organizationId)
                ->lockForUpdate()
                ->first();

            return ((int) static::where('organization_id', $organizationId)->max('icv')) + 1;
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
     * Compliance profile used for this invoice's jurisdiction.
     */
    public function complianceProfile(): BelongsTo
    {
        return $this->belongsTo(ComplianceProfile::class);
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
