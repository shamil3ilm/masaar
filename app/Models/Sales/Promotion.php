<?php

declare(strict_types=1);

namespace App\Models\Sales;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Inventory\Product;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Promotion extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id',
        'name',
        'code',
        'description',
        'type',
        'apply_to',
        'target',
        'discount_value',
        'max_discount_amount',
        'buy_quantity',
        'get_quantity',
        'get_discount_percent',
        'tiers',
        'min_order_amount',
        'min_quantity',
        'max_uses',
        'max_uses_per_customer',
        'current_uses',
        'start_date',
        'end_date',
        'valid_days',
        'valid_time_start',
        'valid_time_end',
        'is_stackable',
        'is_exclusive',
        'priority',
        'is_active',
        'requires_code',
        'created_by',
    ];

    protected $casts = [
        'discount_value' => 'decimal:4',
        'max_discount_amount' => 'decimal:4',
        'buy_quantity' => 'integer',
        'get_quantity' => 'integer',
        'get_discount_percent' => 'decimal:2',
        'tiers' => 'array',
        'min_order_amount' => 'decimal:4',
        'min_quantity' => 'decimal:4',
        'max_uses' => 'integer',
        'max_uses_per_customer' => 'integer',
        'current_uses' => 'integer',
        'start_date' => 'datetime',
        'end_date' => 'datetime',
        'valid_days' => 'array',
        'valid_time_start' => 'datetime:H:i',
        'valid_time_end' => 'datetime:H:i',
        'is_stackable' => 'boolean',
        'is_exclusive' => 'boolean',
        'priority' => 'integer',
        'is_active' => 'boolean',
        'requires_code' => 'boolean',
    ];

    // Promotion types
    public const TYPE_PERCENTAGE = 'percentage';
    public const TYPE_FIXED_AMOUNT = 'fixed_amount';
    public const TYPE_FIXED_PRICE = 'fixed_price';
    public const TYPE_BUY_X_GET_Y = 'buy_x_get_y';
    public const TYPE_BUNDLE = 'bundle';
    public const TYPE_TIERED = 'tiered';
    public const TYPE_FREE_SHIPPING = 'free_shipping';

    // Apply to
    public const APPLY_LINE = 'line';
    public const APPLY_ORDER = 'order';
    public const APPLY_SHIPPING = 'shipping';

    // Target
    public const TARGET_ALL = 'all';
    public const TARGET_PRODUCTS = 'specific_products';
    public const TARGET_CATEGORIES = 'specific_categories';
    public const TARGET_CUSTOMERS = 'specific_customers';
    public const TARGET_GROUPS = 'customer_groups';

    // Relationships

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'promotion_products')
            ->withPivot('is_excluded')
            ->wherePivot('is_excluded', false);
    }

    public function excludedProducts(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'promotion_products')
            ->withPivot('is_excluded')
            ->wherePivot('is_excluded', true);
    }

    public function customers(): BelongsToMany
    {
        return $this->belongsToMany(Contact::class, 'promotion_customers')
            ->withPivot('is_excluded')
            ->wherePivot('is_excluded', false);
    }

    public function customerGroups(): BelongsToMany
    {
        return $this->belongsToMany(CustomerGroup::class, 'promotion_customers')
            ->withPivot('is_excluded')
            ->wherePivot('is_excluded', false);
    }

    public function usages(): HasMany
    {
        return $this->hasMany(PromotionUsage::class);
    }

    public function couponCodes(): HasMany
    {
        return $this->hasMany(CouponCode::class);
    }

    // Scopes

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeValid($query, ?Carbon $date = null)
    {
        $date = $date ?? now();

        return $query->where('is_active', true)
            ->where('start_date', '<=', $date)
            ->where(function ($q) use ($date) {
                $q->whereNull('end_date')
                    ->orWhere('end_date', '>=', $date);
            });
    }

    public function scopeAutoApply($query)
    {
        return $query->where('requires_code', false);
    }

    public function scopeRequiresCode($query)
    {
        return $query->where('requires_code', true);
    }

    // Validation

    public function isValid(?Carbon $date = null): bool
    {
        $date = $date ?? now();

        if (!$this->is_active) {
            return false;
        }

        if ($this->start_date->gt($date)) {
            return false;
        }

        if ($this->end_date && $this->end_date->lt($date)) {
            return false;
        }

        if ($this->max_uses && $this->current_uses >= $this->max_uses) {
            return false;
        }

        // Check valid days
        if ($this->valid_days && !in_array($date->dayOfWeek, $this->valid_days)) {
            return false;
        }

        // Check valid time
        if ($this->valid_time_start && $this->valid_time_end) {
            $currentTime = $date->format('H:i:s');
            if ($currentTime < $this->valid_time_start || $currentTime > $this->valid_time_end) {
                return false;
            }
        }

        return true;
    }

    public function isValidForCustomer(?int $customerId): bool
    {
        if ($this->target === self::TARGET_ALL) {
            return true;
        }

        if (!$customerId) {
            return $this->target === self::TARGET_ALL;
        }

        // Check customer-specific
        if ($this->target === self::TARGET_CUSTOMERS) {
            return $this->customers()->where('contacts.id', $customerId)->exists();
        }

        // Check customer group
        if ($this->target === self::TARGET_GROUPS) {
            $customer = Contact::find($customerId);
            if ($customer?->customer_group_id) {
                return $this->customerGroups()->where('customer_groups.id', $customer->customer_group_id)->exists();
            }
            return false;
        }

        return true;
    }

    public function isValidForProduct(int $productId): bool
    {
        // Check if excluded
        if ($this->excludedProducts()->where('products.id', $productId)->exists()) {
            return false;
        }

        if ($this->target === self::TARGET_ALL || $this->target === self::TARGET_CUSTOMERS || $this->target === self::TARGET_GROUPS) {
            return true;
        }

        if ($this->target === self::TARGET_PRODUCTS) {
            return $this->products()->where('products.id', $productId)->exists();
        }

        if ($this->target === self::TARGET_CATEGORIES) {
            $product = Product::find($productId);
            if ($product?->category_id) {
                return $this->belongsToMany(\App\Models\Inventory\Category::class, 'promotion_products', 'promotion_id', 'category_id')
                    ->wherePivot('is_excluded', false)
                    ->where('categories.id', $product->category_id)
                    ->exists();
            }
        }

        return false;
    }

    public function canUse(?int $customerId): bool
    {
        if (!$this->isValid()) {
            return false;
        }

        if (!$this->isValidForCustomer($customerId)) {
            return false;
        }

        if ($this->max_uses_per_customer && $customerId) {
            $customerUses = $this->usages()->where('contact_id', $customerId)->count();
            if ($customerUses >= $this->max_uses_per_customer) {
                return false;
            }
        }

        return true;
    }

    // Calculation

    public function calculateDiscount(string $amount, float $quantity = 1, ?array $context = []): DiscountResult
    {
        if (!$this->isValid()) {
            return new DiscountResult('0', '0', false, 'Promotion is not valid');
        }

        // Check minimum order amount
        if ($this->min_order_amount && bccomp($amount, (string) $this->min_order_amount, 4) < 0) {
            return new DiscountResult('0', '0', false, 'Minimum order amount not met');
        }

        // Check minimum quantity
        if ($this->min_quantity && $quantity < (float) $this->min_quantity) {
            return new DiscountResult('0', '0', false, 'Minimum quantity not met');
        }

        return match ($this->type) {
            self::TYPE_PERCENTAGE => $this->calculatePercentageDiscount($amount),
            self::TYPE_FIXED_AMOUNT => $this->calculateFixedAmountDiscount($amount),
            self::TYPE_FIXED_PRICE => $this->calculateFixedPriceDiscount($amount),
            self::TYPE_BUY_X_GET_Y => $this->calculateBuyXGetYDiscount($amount, $quantity, $context),
            self::TYPE_TIERED => $this->calculateTieredDiscount($amount, $quantity),
            default => new DiscountResult('0', '0', false, 'Unknown promotion type'),
        };
    }

    protected function calculatePercentageDiscount(string $amount): DiscountResult
    {
        $discount = bcmul($amount, bcdiv((string) $this->discount_value, '100', 6), 4);

        // Apply cap
        if ($this->max_discount_amount && bccomp($discount, (string) $this->max_discount_amount, 4) > 0) {
            $discount = (string) $this->max_discount_amount;
        }

        $finalAmount = bcsub($amount, $discount, 4);

        return new DiscountResult($discount, $finalAmount, true);
    }

    protected function calculateFixedAmountDiscount(string $amount): DiscountResult
    {
        $discount = (string) $this->discount_value;

        // Can't discount more than the amount
        if (bccomp($discount, $amount, 4) > 0) {
            $discount = $amount;
        }

        $finalAmount = bcsub($amount, $discount, 4);

        return new DiscountResult($discount, $finalAmount, true);
    }

    protected function calculateFixedPriceDiscount(string $amount): DiscountResult
    {
        $fixedPrice = (string) $this->discount_value;
        $discount = bcsub($amount, $fixedPrice, 4);

        if (bccomp($discount, '0', 4) < 0) {
            $discount = '0';
        }

        return new DiscountResult($discount, $fixedPrice, true);
    }

    protected function calculateBuyXGetYDiscount(string $amount, float $quantity, array $context): DiscountResult
    {
        $buyQty = $this->buy_quantity ?? 1;
        $getQty = $this->get_quantity ?? 1;
        $getDiscount = $this->get_discount_percent ?? 100; // Default = free

        // How many times can this promotion apply?
        $applicableTimes = floor($quantity / ($buyQty + $getQty));

        if ($applicableTimes < 1) {
            return new DiscountResult('0', $amount, false, 'Not enough quantity');
        }

        // Calculate unit price
        $unitPrice = bcdiv($amount, (string) $quantity, 4);
        $discountedItems = $applicableTimes * $getQty;
        $discountPerItem = bcmul($unitPrice, bcdiv((string) $getDiscount, '100', 6), 4);
        $totalDiscount = bcmul($discountPerItem, (string) $discountedItems, 4);

        $finalAmount = bcsub($amount, $totalDiscount, 4);

        return new DiscountResult($totalDiscount, $finalAmount, true);
    }

    protected function calculateTieredDiscount(string $amount, float $quantity): DiscountResult
    {
        if (empty($this->tiers)) {
            return new DiscountResult('0', $amount, false, 'No tiers configured');
        }

        // Find applicable tier
        $applicableTier = null;
        foreach ($this->tiers as $tier) {
            if ($quantity >= ($tier['min_quantity'] ?? 0)) {
                $applicableTier = $tier;
            }
        }

        if (!$applicableTier) {
            return new DiscountResult('0', $amount, false, 'No applicable tier');
        }

        $discountPercent = $applicableTier['discount_percent'] ?? 0;
        $discount = bcmul($amount, bcdiv((string) $discountPercent, '100', 6), 4);
        $finalAmount = bcsub($amount, $discount, 4);

        return new DiscountResult($discount, $finalAmount, true);
    }

    // Usage tracking

    public function recordUsage(int $orderId, string $orderType, ?int $customerId, string $discountAmount): void
    {
        $this->usages()->create([
            'contact_id' => $customerId,
            'order_type' => $orderType,
            'order_id' => $orderId,
            'discount_amount' => $discountAmount,
        ]);

        $this->increment('current_uses');
    }
}

class DiscountResult
{
    public function __construct(
        public readonly string $discountAmount,
        public readonly string $finalAmount,
        public readonly bool $applied,
        public readonly ?string $message = null
    ) {}
}
