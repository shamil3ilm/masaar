<?php

declare(strict_types=1);

namespace App\Domains\Licensing\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * License Registration Model.
 *
 * Tracks users/organizations that register to use Masaar commercially.
 * Required by the Controlled Open Source License (COSL).
 *
 * @property string $id
 * @property string|null $organization_id
 * @property string $organization_name
 * @property string $contact_name
 * @property string $contact_email
 * @property string|null $vat_number
 * @property string $use_case_description
 * @property bool $terms_accepted
 * @property \DateTime|null $terms_accepted_at
 * @property string $terms_version
 * @property string|null $accepted_from_ip
 * @property string $status
 * @property string $license_type
 * @property string|null $rejection_reason
 * @property string|null $verification_token
 * @property \DateTime|null $verified_at
 * @property string $country_code
 * @property string|null $industry
 * @property string|null $company_size
 * @property string|null $admin_notes
 * @property string|null $approved_by
 * @property \DateTime|null $approved_at
 */
class LicenseRegistration extends Model
{
    use HasFactory;
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'organization_id',
        'organization_name',
        'contact_name',
        'contact_email',
        'vat_number',
        'use_case_description',
        'terms_accepted',
        'terms_accepted_at',
        'terms_version',
        'accepted_from_ip',
        'status',
        'license_type',
        'rejection_reason',
        'verification_token',
        'verified_at',
        'country_code',
        'industry',
        'company_size',
        'admin_notes',
        'approved_by',
        'approved_at',
    ];

    protected $casts = [
        'terms_accepted' => 'boolean',
        'terms_accepted_at' => 'datetime',
        'verified_at' => 'datetime',
        'approved_at' => 'datetime',
    ];

    protected $hidden = [
        'verification_token',
        'admin_notes',
    ];

    // Status constants
    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_SUSPENDED = 'suspended';
    public const STATUS_REVOKED = 'revoked';

    // License type constants
    public const TYPE_COMMERCIAL = 'commercial';
    public const TYPE_EDUCATIONAL = 'educational';
    public const TYPE_NON_PROFIT = 'non-profit';

    // Company size constants
    public const SIZE_SMALL = 'small';       // 1-50 employees
    public const SIZE_MEDIUM = 'medium';     // 51-250 employees
    public const SIZE_LARGE = 'large';       // 251-1000 employees
    public const SIZE_ENTERPRISE = 'enterprise'; // 1000+ employees

    /**
     * Get the organization this registration is linked to.
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(\App\Domains\Organization\Models\Organization::class);
    }

    /**
     * Get the user who approved this registration.
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * Get audit logs for this registration.
     */
    public function audits(): HasMany
    {
        return $this->hasMany(LicenseRegistrationAudit::class, 'registration_id');
    }

    /**
     * Check if registration is approved.
     */
    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    /**
     * Check if registration is pending.
     */
    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /**
     * Check if registration is active (can use the software).
     */
    public function isActive(): bool
    {
        return $this->status === self::STATUS_APPROVED && $this->terms_accepted;
    }

    /**
     * Check if email is verified.
     */
    public function isVerified(): bool
    {
        return $this->verified_at !== null;
    }

    /**
     * Generate verification token.
     */
    public function generateVerificationToken(): string
    {
        $this->verification_token = bin2hex(random_bytes(32));
        $this->save();

        return $this->verification_token;
    }

    /**
     * Verify the registration.
     */
    public function verify(): bool
    {
        $this->verified_at = now();
        $this->verification_token = null;

        return $this->save();
    }

    /**
     * Approve the registration.
     */
    public function approve(User $approver, ?string $notes = null): bool
    {
        $oldStatus = $this->status;

        $this->status = self::STATUS_APPROVED;
        $this->approved_by = $approver->id;
        $this->approved_at = now();

        if ($notes) {
            $this->admin_notes = $notes;
        }

        $saved = $this->save();

        if ($saved) {
            $this->logAudit('approved', $oldStatus, self::STATUS_APPROVED, $notes);
        }

        return $saved;
    }

    /**
     * Reject the registration.
     */
    public function reject(User $rejector, string $reason): bool
    {
        $oldStatus = $this->status;

        $this->status = self::STATUS_REJECTED;
        $this->rejection_reason = $reason;
        $this->approved_by = $rejector->id;

        $saved = $this->save();

        if ($saved) {
            $this->logAudit('rejected', $oldStatus, self::STATUS_REJECTED, $reason);
        }

        return $saved;
    }

    /**
     * Suspend the registration.
     */
    public function suspend(User $suspender, string $reason): bool
    {
        $oldStatus = $this->status;

        $this->status = self::STATUS_SUSPENDED;
        $this->admin_notes = ($this->admin_notes ?? '') . "\n[Suspended] " . $reason;

        $saved = $this->save();

        if ($saved) {
            $this->logAudit('suspended', $oldStatus, self::STATUS_SUSPENDED, $reason);
        }

        return $saved;
    }

    /**
     * Revoke the registration.
     */
    public function revoke(User $revoker, string $reason): bool
    {
        $oldStatus = $this->status;

        $this->status = self::STATUS_REVOKED;
        $this->admin_notes = ($this->admin_notes ?? '') . "\n[Revoked] " . $reason;

        $saved = $this->save();

        if ($saved) {
            $this->logAudit('revoked', $oldStatus, self::STATUS_REVOKED, $reason);
        }

        return $saved;
    }

    /**
     * Log an audit entry.
     */
    protected function logAudit(
        string $action,
        ?string $oldStatus = null,
        ?string $newStatus = null,
        ?string $reason = null,
        ?array $changes = null
    ): void {
        LicenseRegistrationAudit::create([
            'registration_id' => $this->id,
            'user_id' => auth()->id(),
            'action' => $action,
            'old_status' => $oldStatus,
            'new_status' => $newStatus,
            'changes' => $changes,
            'reason' => $reason,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);
    }

    /**
     * Scope: Approved registrations.
     */
    public function scopeApproved($query)
    {
        return $query->where('status', self::STATUS_APPROVED);
    }

    /**
     * Scope: Pending registrations.
     */
    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    /**
     * Scope: Active registrations.
     */
    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_APPROVED)
            ->where('terms_accepted', true);
    }
}
