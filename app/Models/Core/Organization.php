<?php

declare(strict_types=1);

namespace App\Models\Core;

use App\Models\Concerns\HasAuditTrail;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Organization extends Model
{
    use HasFactory, SoftDeletes, HasUuid, HasAuditTrail;

    protected $fillable = [
        'name',
        'legal_name',
        'slug',
        'country_code',
        'tax_scheme',
        'tax_number',
        'base_currency',
        'fiscal_year_start_month',
        'fiscal_year_start_day',
        'email',
        'phone',
        'website',
        'address_line_1',
        'address_line_2',
        'city',
        'state',
        'postal_code',
        'settings',
        'logo_url',
        'is_active',
        'activated_at',
        'suspended_at',
    ];

    protected $casts = [
        'settings' => 'array',
        'is_active' => 'boolean',
        'fiscal_year_start_month' => 'integer',
        'fiscal_year_start_day' => 'integer',
        'activated_at' => 'datetime',
        'suspended_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (Organization $organization): void {
            if (empty($organization->slug)) {
                $organization->slug = Str::slug($organization->name);
            }
        });
    }

    // Relationships
    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function roles(): HasMany
    {
        return $this->hasMany(Role::class);
    }

    // Accessors
    public function getDefaultBranchAttribute(): ?Branch
    {
        return $this->branches()->where('is_default', true)->first();
    }

    // Methods
    public function requiresCompliance(): bool
    {
        // Countries that require e-invoicing compliance
        $complianceCountries = ['SA', 'AE', 'IN'];

        return in_array($this->country_code, $complianceCountries, true);
    }

    public function getTaxSchemeDetails(): array
    {
        return match ($this->tax_scheme) {
            'VAT' => [
                'name' => 'Value Added Tax',
                'rate' => $this->getStandardVatRate(),
            ],
            'GST' => [
                'name' => 'Goods and Services Tax',
                'slabs' => [0, 5, 12, 18, 28],
            ],
            default => [
                'name' => 'No Tax',
                'rate' => 0,
            ],
        };
    }

    public function getStandardVatRate(): float
    {
        return match ($this->country_code) {
            'SA' => 15.0,
            'AE', 'BH', 'OM' => 5.0,
            'QA', 'KW' => 0.0,
            default => 0.0,
        };
    }

    public function getSetting(string $group, string $key, mixed $default = null): mixed
    {
        $settings = $this->settings ?? [];

        return $settings[$group][$key] ?? $default;
    }

    public function setSetting(string $group, string $key, mixed $value): void
    {
        $settings = $this->settings ?? [];
        $settings[$group][$key] = $value;
        $this->settings = $settings;
        $this->save();
    }
}
