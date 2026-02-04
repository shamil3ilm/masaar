<?php

declare(strict_types=1);

namespace App\Models\Sales;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PriceList extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id',
        'name',
        'code',
        'description',
        'type',
        'currency_code',
        'is_default',
        'is_tax_inclusive',
        'valid_from',
        'valid_until',
        'customer_group_id',
        'priority',
        'is_active',
    ];

    protected $casts = [
        'is_default' => 'boolean',
        'is_tax_inclusive' => 'boolean',
        'valid_from' => 'date',
        'valid_until' => 'date',
        'priority' => 'integer',
        'is_active' => 'boolean',
    ];

    public const TYPE_SELLING = 'selling';
    public const TYPE_BUYING = 'buying';

    // Relationships

    public function customerGroup(): BelongsTo
    {
        return $this->belongsTo(CustomerGroup::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PriceListItem::class);
    }

    // Scopes

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeSelling($query)
    {
        return $query->where('type', self::TYPE_SELLING);
    }

    public function scopeBuying($query)
    {
        return $query->where('type', self::TYPE_BUYING);
    }

    public function scopeValid($query, $date = null)
    {
        $date = $date ?? today();

        return $query->where(function ($q) use ($date) {
            $q->whereNull('valid_from')->orWhere('valid_from', '<=', $date);
        })->where(function ($q) use ($date) {
            $q->whereNull('valid_until')->orWhere('valid_until', '>=', $date);
        });
    }

    public function scopeForCustomerGroup($query, ?int $groupId)
    {
        return $query->where(function ($q) use ($groupId) {
            $q->whereNull('customer_group_id');
            if ($groupId) {
                $q->orWhere('customer_group_id', $groupId);
            }
        });
    }

    public function scopeDefault($query)
    {
        return $query->where('is_default', true);
    }

    // Helpers

    public function isValid(): bool
    {
        $today = today();

        if ($this->valid_from && $this->valid_from->gt($today)) {
            return false;
        }

        if ($this->valid_until && $this->valid_until->lt($today)) {
            return false;
        }

        return $this->is_active;
    }

    public function isTaxInclusive(): bool
    {
        return $this->is_tax_inclusive;
    }

    public function getPrice(int $productId, float $quantity = 1): ?PriceListItem
    {
        return $this->items()
            ->where('product_id', $productId)
            ->where('min_quantity', '<=', $quantity)
            ->where(function ($q) use ($quantity) {
                $q->whereNull('max_quantity')
                    ->orWhere('max_quantity', '>=', $quantity);
            })
            ->orderBy('min_quantity', 'desc')
            ->first();
    }
}
