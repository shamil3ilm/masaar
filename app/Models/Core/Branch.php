<?php

declare(strict_types=1);

namespace App\Models\Core;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasAuditTrail;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Crypt;

class Branch extends Model
{
    use HasFactory, SoftDeletes, HasUuid, BelongsToOrganization, HasAuditTrail;

    protected $fillable = [
        'organization_id',
        'name',
        'code',
        'address_line_1',
        'address_line_2',
        'city',
        'state',
        'postal_code',
        'country_code',
        'phone',
        'email',
        'tax_number',
        'compliance_credentials',
        'compliance_status',
        'is_default',
        'is_active',
    ];

    protected $casts = [
        'is_default' => 'boolean',
        'is_active' => 'boolean',
    ];

    protected $hidden = [
        'compliance_credentials',
    ];

    // Fields to exclude from audit trail
    protected array $auditExclude = [
        'compliance_credentials',
    ];

    // Relationships
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_branches')
            ->withPivot('is_default')
            ->withTimestamps();
    }

    // Accessors & Mutators for encrypted compliance credentials
    public function getComplianceCredentialsAttribute(?string $value): ?array
    {
        if ($value === null) {
            return null;
        }

        try {
            return json_decode(Crypt::decryptString($value), true);
        } catch (\Exception) {
            return null;
        }
    }

    public function setComplianceCredentialsAttribute(?array $value): void
    {
        if ($value === null) {
            $this->attributes['compliance_credentials'] = null;
            return;
        }

        $this->attributes['compliance_credentials'] = Crypt::encryptString(json_encode($value));
    }

    // Methods
    public function getFullAddressAttribute(): string
    {
        $parts = array_filter([
            $this->address_line_1,
            $this->address_line_2,
            $this->city,
            $this->state,
            $this->postal_code,
            $this->country_code,
        ]);

        return implode(', ', $parts);
    }

    public function isComplianceActive(): bool
    {
        return $this->compliance_status === 'active';
    }

    public function getComplianceCredential(string $key): ?string
    {
        return $this->compliance_credentials[$key] ?? null;
    }

    public function setComplianceCredential(string $key, string $value): void
    {
        $credentials = $this->compliance_credentials ?? [];
        $credentials[$key] = $value;
        $this->compliance_credentials = $credentials;
        $this->save();
    }

    public function markAsDefault(): void
    {
        // Remove default from other branches in same organization
        static::where('organization_id', $this->organization_id)
            ->where('id', '!=', $this->id)
            ->update(['is_default' => false]);

        $this->update(['is_default' => true]);
    }
}
