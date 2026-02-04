<?php

declare(strict_types=1);

namespace App\Models\Sales;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CustomerGroup extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id',
        'name',
        'code',
        'description',
        'default_discount_percent',
        'credit_limit',
        'payment_terms_days',
        'tax_exempt',
        'wholesale',
        'is_active',
        'priority',
    ];

    protected $casts = [
        'default_discount_percent' => 'decimal:2',
        'credit_limit' => 'decimal:4',
        'payment_terms_days' => 'integer',
        'tax_exempt' => 'boolean',
        'wholesale' => 'boolean',
        'is_active' => 'boolean',
        'priority' => 'integer',
    ];

    // Common group codes
    public const RETAIL = 'RETAIL';
    public const WHOLESALE = 'WHOLESALE';
    public const DISTRIBUTOR = 'DISTRIBUTOR';
    public const VIP = 'VIP';
    public const GOVERNMENT = 'GOVERNMENT';
    public const WALK_IN = 'WALKIN';

    // Relationships

    public function customers(): HasMany
    {
        return $this->hasMany(Contact::class, 'customer_group_id');
    }

    public function priceLists(): HasMany
    {
        return $this->hasMany(PriceList::class);
    }

    // Scopes

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeWholesale($query)
    {
        return $query->where('wholesale', true);
    }

    public function scopeRetail($query)
    {
        return $query->where('wholesale', false);
    }

    // Helpers

    public function isWholesale(): bool
    {
        return $this->wholesale;
    }

    public function isTaxExempt(): bool
    {
        return $this->tax_exempt;
    }

    public function hasCredit(): bool
    {
        return $this->payment_terms_days > 0;
    }
}
