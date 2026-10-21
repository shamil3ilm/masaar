<?php

declare(strict_types=1);

namespace App\Domains\Organization\Models;

use App\Domains\Compliance\Countries\SaudiArabia\DTOs\AddressData;
use App\Domains\Compliance\Countries\SaudiArabia\DTOs\CsrData;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Concerns\BelongsToTenant;
use App\Domains\Organization\Concerns\RecordsActors;
use App\Domains\Organization\Services\Tills;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

/**
 * Branch model for multi-EGS support.
 *
 * Each branch represents a physical location or EGS device
 * that can have its own Fatoora certificate and invoice stream.
 *
 * @property string $id
 * @property string $org_id
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
 * @property Carbon|null $onboarded_at
 * @property Carbon|null $cert_expires_at
 * @property int $invoice_count
 * @property Carbon|null $last_invoice_at
 * @property bool $is_active
 * @property bool $is_default
 * @property int|null $till_number
 */
class Branch extends Model
{
    use BelongsToTenant;
    use HasUuids;
    use RecordsActors;
    use SoftDeletes;

    protected $fillable = [
        'org_id',
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
        // The emirate, for a branch in the UAE (the VAT return's boxes 1a-1g).
        'emirate',
        'onboarding_status',
        'onboarded_at',
        'cert_expires_at',
        'invoice_count',
        'last_invoice_at',
        'is_active',
        'is_default',
    ];

    protected $casts = [
        'onboarded_at' => 'datetime',
        'cert_expires_at' => 'datetime',
        'last_invoice_at' => 'datetime',
        'invoice_count' => 'integer',
        'till_number' => 'integer',
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
     * A new branch is the organization's next till: its number follows the
     * highest given, a removed branch's and any till's included, so a till's
     * numbering series (InvoiceNumbering) is never another's.
     *
     * And it comes with its first till: a unit under the branch's own id,
     * which is the device a branch always was. More tills can join it.
     */
    protected static function booted(): void
    {
        static::creating(function (Branch $branch): void {
            $branch->till_number ??= EgsUnit::nextTillNumber((string) $branch->org_id);
        });

        static::created(function (Branch $branch): void {
            EgsUnit::withoutTenantScope(fn () => (new EgsUnit)->forceFill([
                'id' => $branch->id,
                'org_id' => $branch->org_id,
                'branch_id' => $branch->id,
                'till_number' => $branch->till_number,
                'egs_serial' => $branch->device_serial,
                'onboarding_status' => $branch->onboarding_status,
                'cert_expires_at' => $branch->cert_expires_at,
            ])->save());
        });

        // The branch's onboarding columns are its first till's: the branch
        // onboarding API and the console still write them on the branch.
        static::updated(function (Branch $branch): void {
            if ($branch->wasChanged(['onboarding_status', 'cert_expires_at'])) {
                EgsUnit::withoutTenantScope(fn () => EgsUnit::query()->whereKey($branch->id)->update([
                    'onboarding_status' => $branch->onboarding_status,
                    'cert_expires_at' => $branch->cert_expires_at,
                    'updated_at' => now(),
                ]));
            }
        });
    }

    /**
     * The branch's tills, its first one included.
     */
    public function units(): HasMany
    {
        return $this->hasMany(EgsUnit::class);
    }

    /**
     * Get the organization that owns this branch.
     */
    public function org(): BelongsTo
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
            && ! $this->isCertificateExpired();
    }

    /**
     * Check if certificate is expired.
     */
    public function isCertificateExpired(): bool
    {
        if (! $this->cert_expires_at) {
            return false;
        }

        return $this->cert_expires_at->isPast();
    }

    /**
     * Check if certificate is expiring soon (within 30 days).
     */
    public function isCertificateExpiringSoon(): bool
    {
        if (! $this->cert_expires_at) {
            return false;
        }

        return $this->cert_expires_at->diffInDays(now()) <= 30;
    }

    /**
     * Get days until certificate expiration.
     */
    public function getDaysUntilCertificateExpiry(): ?int
    {
        if (! $this->cert_expires_at) {
            return null;
        }

        return (int) now()->diffInDays($this->cert_expires_at, false);
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
            // AddressData has no additionalNumber: the name threw on every
            // call. The organization's own additional number goes in the
            // same place (Organization::getAddressData).
            additionalStreet: $this->additional_number,
            countryCode: $this->country_code,
        );
    }

    /**
     * Get CSR data for certificate generation.
     */
    public function getCsrData(): CsrData
    {
        $organization = $this->org;

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
        // A branch created with a till's serial (generateDeviceSerial) is
        // already 1-solution|2-version|3-unit, and goes into its request as
        // it is, as a till's does. One created before keeps the request it
        // was certified under.
        if ($this->device_serial === Tills::serialFor((string) $this->id)) {
            return $this->device_serial;
        }

        $orgName = substr(preg_replace('/[^A-Za-z0-9]/', '', $this->org->name), 0, 20);
        $branchName = substr(preg_replace('/[^A-Za-z0-9]/', '', $this->name), 0, 20);

        return sprintf(
            '1-%s|2-%s|3-%s',
            $orgName,
            $branchName,
            substr($this->device_serial, 0, 20)
        );
    }

    /**
     * The device serial a new branch's first till is onboarded under.
     *
     * The same as every till's (Tills::serialFor): the solution, its version
     * and the unit's own id - a branch's first till has the branch's id.
     * It used the VAT number, a per-organization count and the solution, a
     * second format for the same thing.
     *
     * Not a label: getCsrData() passes device_serial as the CSR common name,
     * so it is signed into the CSID ZATCA issues and identifies the EGS unit
     * from then on. Generated once, for a new branch; a branch keeps the one
     * it was created with, and one already onboarded is never renamed.
     */
    public static function generateDeviceSerial(string $branchId): string
    {
        return Tills::serialFor($branchId);
    }

    /**
     * Increment invoice count.
     */
    public function incrementInvoiceCount(): void
    {
        // One statement, so the count and the time of the latest invoice
        // cannot disagree.
        $this->increment('invoice_count', 1, ['last_invoice_at' => now()]);
    }

    /**
     * Mark branch as active (PCSID obtained).
     */
    public function markAsActive(?\DateTime $certificateExpiry = null): void
    {
        $this->update([
            'onboarding_status' => self::STATUS_ACTIVE,
            'onboarded_at' => now(),
            'cert_expires_at' => $certificateExpiry,
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
     *
     * Clearing the other branches and marking this one happen in one
     * transaction holding the organization row. Apart, a failure between the
     * two leaves the organization with no default; unserialised, two branches
     * made default at the same time can both end up marked.
     */
    public function setAsDefault(): void
    {
        DB::transaction(function (): void {
            static::lockOrganization($this->org_id);

            static::where('org_id', $this->org_id)
                ->where('id', '!=', $this->id)
                ->update(['is_default' => false]);

            $this->update(['is_default' => true]);
        });
    }

    /**
     * Serialise decisions about an organization's branches.
     *
     * Which branch is default, and whether the organization has a branch at
     * all, are read and then written; holding the organization row makes a
     * concurrent request wait for this one's answer. Call inside a
     * transaction: the lock lasts until it commits. The organization row is
     * locked rather than branch rows because an organization with no branches
     * has none to lock.
     */
    public static function lockOrganization(string $organizationId): void
    {
        DB::table('organizations')->where('id', $organizationId)->lockForUpdate()->first();
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
                $q->whereNull('cert_expires_at')
                    ->orWhere('cert_expires_at', '>', now());
            });
    }
}
