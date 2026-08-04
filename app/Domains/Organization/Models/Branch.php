<?php

declare(strict_types=1);

namespace App\Domains\Organization\Models;

use App\Domains\Organization\Concerns\BelongsToTenant;
use App\Domains\Compliance\Fatoora\DTOs\AddressData;
use App\Domains\Compliance\Fatoora\DTOs\CsrData;
use App\Domains\Invoice\Models\Invoice;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Branch model for multi-EGS support.
 *
 * Each branch represents a physical location or EGS device
 * that can have its own Fatoora certificate and invoice stream.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $name
 * @property string|null $name_ar
 * @property string $device_serial
 * @property string $industry
 * @property string $street
 * @property string $building_number
 * @property string|null $additional_number
 * @property string $district
 * @property string $city
 * @property string $postal_code
 * @property string $country_code
 * @property string $onboarding_status
 * @property \Carbon\Carbon|null $onboarded_at
 * @property \Carbon\Carbon|null $certificate_expires_at
 * @property int $invoice_count
 * @property \Carbon\Carbon|null $last_invoice_at
 * @property bool $is_active
 * @property bool $is_default
 */
class Branch extends Model
{
    use BelongsToTenant;
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'organization_id',
        'name',
        'name_ar',
        'device_serial',
        'industry',
        'street',
        'building_number',
        'additional_number',
        'district',
        'city',
        'postal_code',
        'country_code',
        'onboarding_status',
        'onboarded_at',
        'certificate_expires_at',
        'invoice_count',
        'last_invoice_at',
        'is_active',
        'is_default',
    ];

    protected $casts = [
        'onboarded_at' => 'datetime',
        'certificate_expires_at' => 'datetime',
        'last_invoice_at' => 'datetime',
        'invoice_count' => 'integer',
        'is_active' => 'boolean',
        'is_default' => 'boolean',
    ];

    protected $attributes = [
        'onboarding_status' => 'pending',
        'country_code' => 'SA',
        'industry' => 'General',
        'invoice_count' => 0,
        'is_active' => true,
        'is_default' => false,
    ];

    // Onboarding status constants
    public const STATUS_PENDING = 'pending';
    public const STATUS_CSR_GENERATED = 'csr_generated';
    public const STATUS_COMPLIANCE_PASSED = 'compliance_passed';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_SUSPENDED = 'suspended';
    public const STATUS_REVOKED = 'revoked';

    /**
     * Get the organization that owns this branch.
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Get invoices issued by this branch.
     */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    /**
     * Check if branch is ready to issue invoices.
     */
    public function isFatooraReady(): bool
    {
        return $this->onboarding_status === self::STATUS_ACTIVE
            && $this->is_active
            && !$this->isCertificateExpired();
    }

    /**
     * Check if certificate is expired.
     */
    public function isCertificateExpired(): bool
    {
        if (!$this->certificate_expires_at) {
            return false;
        }

        return $this->certificate_expires_at->isPast();
    }

    /**
     * Check if certificate is expiring soon (within 30 days).
     */
    public function isCertificateExpiringSoon(): bool
    {
        if (!$this->certificate_expires_at) {
            return false;
        }

        return $this->certificate_expires_at->diffInDays(now()) <= 30;
    }

    /**
     * Get days until certificate expiration.
     */
    public function getDaysUntilCertificateExpiry(): ?int
    {
        if (!$this->certificate_expires_at) {
            return null;
        }

        return (int) now()->diffInDays($this->certificate_expires_at, false);
    }

    /**
     * Get address data for XML generation.
     */
    public function getAddressData(): AddressData
    {
        return new AddressData(
            street: $this->street,
            city: $this->city,
            postalCode: $this->postal_code,
            district: $this->district,
            buildingNumber: $this->building_number,
            additionalNumber: $this->additional_number,
            countryCode: $this->country_code,
        );
    }

    /**
     * Get CSR data for certificate generation.
     */
    public function getCsrData(): CsrData
    {
        $organization = $this->organization;

        return new CsrData(
            organizationName: $organization->name,
            organizationUnit: $this->name,
            commonName: $this->device_serial,
            vatNumber: $organization->vat_number,
            serialNumber: $this->generateSerialNumber(),
            location: $this->city,
            industry: $this->industry,
        );
    }

    /**
     * Generate solution serial number for CSR.
     * Format: 1-{ORG_NAME}|2-{BRANCH_NAME}|3-{DEVICE_SERIAL}
     */
    public function generateSerialNumber(): string
    {
        $orgName = substr(preg_replace('/[^A-Za-z0-9]/', '', $this->organization->name), 0, 20);
        $branchName = substr(preg_replace('/[^A-Za-z0-9]/', '', $this->name), 0, 20);

        return sprintf(
            '1-%s|2-%s|3-%s',
            $orgName,
            $branchName,
            substr($this->device_serial, 0, 20)
        );
    }

    /**
     * Generate unique device serial for new branch.
     */
    public static function generateDeviceSerial(Organization $organization): string
    {
        $branchCount = $organization->branches()->withTrashed()->count() + 1;

        return sprintf(
            '1-%s|2-%03d|3-COMPLIPAY',
            $organization->vat_number,
            $branchCount
        );
    }

    /**
     * Increment invoice count.
     */
    public function incrementInvoiceCount(): void
    {
        $this->increment('invoice_count');
        $this->update(['last_invoice_at' => now()]);
    }

    /**
     * Mark branch as active (PCSID obtained).
     */
    public function markAsActive(?\DateTime $certificateExpiry = null): void
    {
        $this->update([
            'onboarding_status' => self::STATUS_ACTIVE,
            'onboarded_at' => now(),
            'certificate_expires_at' => $certificateExpiry,
        ]);
    }

    /**
     * Suspend branch.
     */
    public function suspend(): void
    {
        $this->update([
            'onboarding_status' => self::STATUS_SUSPENDED,
            'is_active' => false,
        ]);
    }

    /**
     * Revoke branch certificate.
     */
    public function revoke(): void
    {
        $this->update([
            'onboarding_status' => self::STATUS_REVOKED,
            'is_active' => false,
        ]);
    }

    /**
     * Set as default branch for organization.
     */
    public function setAsDefault(): void
    {
        // Remove default from other branches
        static::where('organization_id', $this->organization_id)
            ->where('id', '!=', $this->id)
            ->update(['is_default' => false]);

        $this->update(['is_default' => true]);
    }

    /**
     * Scope to active branches.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope to branches ready for invoicing.
     */
    public function scopeFatooraReady($query)
    {
        return $query->where('onboarding_status', self::STATUS_ACTIVE)
            ->where('is_active', true)
            ->where(function ($q) {
                $q->whereNull('certificate_expires_at')
                    ->orWhere('certificate_expires_at', '>', now());
            });
    }

    /**
     * Scope to branches with expiring certificates.
     */
    public function scopeCertificateExpiringSoon($query, int $days = 30)
    {
        return $query->whereNotNull('certificate_expires_at')
            ->where('certificate_expires_at', '<=', now()->addDays($days))
            ->where('certificate_expires_at', '>', now());
    }
}
