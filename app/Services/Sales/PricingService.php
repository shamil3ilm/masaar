<?php

declare(strict_types=1);

namespace App\Services\Sales;

use App\Models\Inventory\Product;
use App\Models\Sales\Contact;
use App\Models\Sales\CustomerGroup;
use App\Models\Sales\CustomerPrice;
use App\Models\Sales\PriceList;
use App\Models\Sales\PriceListItem;
use App\Models\Sales\BulkPricingRule;
use Illuminate\Support\Collection;

class PricingService
{
    /**
     * Get the best price for a product based on customer, quantity, and other factors.
     */
    public function getPrice(
        int $organizationId,
        int $productId,
        float $quantity = 1,
        ?int $customerId = null,
        ?string $priceListCode = null,
        ?string $date = null
    ): PriceResult {
        $date = $date ?? today()->format('Y-m-d');
        $product = Product::find($productId);

        if (!$product) {
            throw new \RuntimeException("Product not found: {$productId}");
        }

        // 1. Check customer-specific pricing first (highest priority)
        if ($customerId) {
            $customerPrice = $this->getCustomerSpecificPrice($customerId, $productId, $quantity, $date);
            if ($customerPrice) {
                return $this->createPriceResult($customerPrice, $product, $quantity, 'customer_specific');
            }
        }

        // 2. Check specified price list
        if ($priceListCode) {
            $priceListPrice = $this->getPriceListPrice($organizationId, $priceListCode, $productId, $quantity, $date);
            if ($priceListPrice) {
                return $this->createPriceResult($priceListPrice, $product, $quantity, 'price_list');
            }
        }

        // 3. Check customer's default price list
        if ($customerId) {
            $customer = Contact::find($customerId);
            if ($customer?->default_price_list_id) {
                $priceList = PriceList::find($customer->default_price_list_id);
                if ($priceList) {
                    $price = $priceList->getPrice($productId, $quantity);
                    if ($price) {
                        return $this->createPriceResult($price, $product, $quantity, 'customer_default_list');
                    }
                }
            }

            // 4. Check customer group price list
            if ($customer?->customer_group_id) {
                $groupPriceList = PriceList::where('organization_id', $organizationId)
                    ->where('customer_group_id', $customer->customer_group_id)
                    ->active()
                    ->valid($date)
                    ->orderBy('priority', 'desc')
                    ->first();

                if ($groupPriceList) {
                    $price = $groupPriceList->getPrice($productId, $quantity);
                    if ($price) {
                        return $this->createPriceResult($price, $product, $quantity, 'customer_group_list');
                    }
                }
            }
        }

        // 5. Check bulk pricing rules
        $bulkPrice = $this->getBulkPrice($organizationId, $productId, $product->category_id, $quantity, $date);
        if ($bulkPrice) {
            return $this->createBulkPriceResult($bulkPrice, $product, $quantity);
        }

        // 6. Get default price list
        $defaultPriceList = PriceList::where('organization_id', $organizationId)
            ->where('type', PriceList::TYPE_SELLING)
            ->where('is_default', true)
            ->active()
            ->valid($date)
            ->first();

        if ($defaultPriceList) {
            $price = $defaultPriceList->getPrice($productId, $quantity);
            if ($price) {
                return $this->createPriceResult($price, $product, $quantity, 'default_list');
            }
        }

        // 7. Fall back to product's selling price
        return new PriceResult(
            unitPrice: (string) $product->selling_price,
            quantity: $quantity,
            discountPercent: '0',
            discountAmount: '0',
            subtotal: bcmul((string) $product->selling_price, (string) $quantity, 4),
            isTaxInclusive: false,
            source: 'product_default',
            priceListId: null,
            tiered: false
        );
    }

    /**
     * Get prices for multiple products at once.
     */
    public function getPrices(
        int $organizationId,
        array $items, // [['product_id' => x, 'quantity' => y], ...]
        ?int $customerId = null,
        ?string $priceListCode = null,
        ?string $date = null
    ): array {
        $results = [];

        foreach ($items as $item) {
            $results[$item['product_id']] = $this->getPrice(
                $organizationId,
                $item['product_id'],
                $item['quantity'] ?? 1,
                $customerId,
                $priceListCode,
                $date
            );
        }

        return $results;
    }

    /**
     * Get customer-specific price.
     */
    protected function getCustomerSpecificPrice(int $customerId, int $productId, float $quantity, string $date): ?CustomerPrice
    {
        return CustomerPrice::where('contact_id', $customerId)
            ->where('product_id', $productId)
            ->where('min_quantity', '<=', $quantity)
            ->where('is_active', true)
            ->where(function ($q) use ($date) {
                $q->whereNull('valid_from')->orWhere('valid_from', '<=', $date);
            })
            ->where(function ($q) use ($date) {
                $q->whereNull('valid_until')->orWhere('valid_until', '>=', $date);
            })
            ->orderBy('min_quantity', 'desc')
            ->first();
    }

    /**
     * Get price from specific price list.
     */
    protected function getPriceListPrice(int $organizationId, string $code, int $productId, float $quantity, string $date): ?PriceListItem
    {
        $priceList = PriceList::where('organization_id', $organizationId)
            ->where('code', $code)
            ->active()
            ->valid($date)
            ->first();

        if (!$priceList) {
            return null;
        }

        return $priceList->getPrice($productId, $quantity);
    }

    /**
     * Get bulk pricing rule.
     */
    protected function getBulkPrice(int $organizationId, int $productId, ?int $categoryId, float $quantity, string $date): ?BulkPricingRule
    {
        return BulkPricingRule::where('organization_id', $organizationId)
            ->where('is_active', true)
            ->where('min_quantity', '<=', $quantity)
            ->where(function ($q) use ($quantity) {
                $q->whereNull('max_quantity')->orWhere('max_quantity', '>=', $quantity);
            })
            ->where(function ($q) use ($productId, $categoryId) {
                $q->where('product_id', $productId);
                if ($categoryId) {
                    $q->orWhere('category_id', $categoryId);
                }
            })
            ->where(function ($q) use ($date) {
                $q->whereNull('valid_from')->orWhere('valid_from', '<=', $date);
            })
            ->where(function ($q) use ($date) {
                $q->whereNull('valid_until')->orWhere('valid_until', '>=', $date);
            })
            ->orderByRaw('product_id IS NULL') // Product-specific first
            ->orderBy('min_quantity', 'desc')
            ->first();
    }

    /**
     * Create price result from price list item.
     */
    protected function createPriceResult($priceItem, Product $product, float $quantity, string $source): PriceResult
    {
        $unitPrice = $priceItem->unit_price ?? $priceItem->price ?? $product->selling_price;
        $discountPercent = $priceItem->discount_percent ?? '0';

        $discountAmount = '0';
        $effectivePrice = $unitPrice;

        if ((float) $discountPercent > 0) {
            $discountAmount = bcmul($unitPrice, bcdiv($discountPercent, '100', 6), 4);
            $effectivePrice = bcsub($unitPrice, $discountAmount, 4);
        }

        $subtotal = bcmul($effectivePrice, (string) $quantity, 4);

        $priceList = $priceItem->priceList ?? ($priceItem->price_list_id ? PriceList::find($priceItem->price_list_id) : null);

        return new PriceResult(
            unitPrice: $effectivePrice,
            quantity: $quantity,
            discountPercent: $discountPercent,
            discountAmount: bcmul($discountAmount, (string) $quantity, 4),
            subtotal: $subtotal,
            isTaxInclusive: $priceList?->is_tax_inclusive ?? false,
            source: $source,
            priceListId: $priceList?->id,
            tiered: $priceItem->min_quantity > 1,
            originalPrice: (string) $unitPrice,
            tierMinQty: (float) $priceItem->min_quantity
        );
    }

    /**
     * Create price result from bulk pricing rule.
     */
    protected function createBulkPriceResult(BulkPricingRule $rule, Product $product, float $quantity): PriceResult
    {
        $basePrice = (string) $product->selling_price;
        $discountAmount = '0';
        $effectivePrice = $basePrice;

        switch ($rule->discount_type) {
            case 'percent':
                $discountAmount = bcmul($basePrice, bcdiv((string) $rule->discount_value, '100', 6), 4);
                $effectivePrice = bcsub($basePrice, $discountAmount, 4);
                break;

            case 'fixed_amount':
                $discountAmount = (string) $rule->discount_value;
                $effectivePrice = bcsub($basePrice, $discountAmount, 4);
                break;

            case 'fixed_price':
                $effectivePrice = (string) $rule->discount_value;
                $discountAmount = bcsub($basePrice, $effectivePrice, 4);
                break;
        }

        $subtotal = bcmul($effectivePrice, (string) $quantity, 4);
        $discountPercent = $basePrice > 0
            ? bcmul(bcdiv($discountAmount, $basePrice, 6), '100', 2)
            : '0';

        return new PriceResult(
            unitPrice: $effectivePrice,
            quantity: $quantity,
            discountPercent: $discountPercent,
            discountAmount: bcmul($discountAmount, (string) $quantity, 4),
            subtotal: $subtotal,
            isTaxInclusive: false,
            source: 'bulk_pricing',
            priceListId: null,
            tiered: true,
            originalPrice: $basePrice,
            tierMinQty: (float) $rule->min_quantity,
            bulkRuleId: $rule->id
        );
    }

    /**
     * Get all available price tiers for a product.
     */
    public function getPriceTiers(int $organizationId, int $productId, ?int $customerId = null): Collection
    {
        $tiers = collect();

        // Get from default price list
        $defaultPriceList = PriceList::where('organization_id', $organizationId)
            ->where('type', PriceList::TYPE_SELLING)
            ->where('is_default', true)
            ->active()
            ->first();

        if ($defaultPriceList) {
            $items = PriceListItem::where('price_list_id', $defaultPriceList->id)
                ->where('product_id', $productId)
                ->valid()
                ->orderBy('min_quantity')
                ->get();

            foreach ($items as $item) {
                $tiers->push([
                    'min_qty' => (float) $item->min_quantity,
                    'max_qty' => $item->max_quantity ? (float) $item->max_quantity : null,
                    'unit_price' => $item->getEffectivePrice(),
                    'source' => 'price_list',
                ]);
            }
        }

        // Get bulk pricing rules
        $product = Product::find($productId);
        $bulkRules = BulkPricingRule::where('organization_id', $organizationId)
            ->where('is_active', true)
            ->where(function ($q) use ($productId, $product) {
                $q->where('product_id', $productId);
                if ($product?->category_id) {
                    $q->orWhere('category_id', $product->category_id);
                }
            })
            ->valid()
            ->orderBy('min_quantity')
            ->get();

        foreach ($bulkRules as $rule) {
            $effectivePrice = $this->calculateBulkEffectivePrice($rule, $product);

            $tiers->push([
                'min_qty' => (float) $rule->min_quantity,
                'max_qty' => $rule->max_quantity ? (float) $rule->max_quantity : null,
                'unit_price' => $effectivePrice,
                'source' => 'bulk_rule',
                'rule_name' => $rule->name,
            ]);
        }

        return $tiers->sortBy('min_qty')->values();
    }

    /**
     * Calculate effective price from bulk rule.
     */
    protected function calculateBulkEffectivePrice(BulkPricingRule $rule, Product $product): string
    {
        $basePrice = (string) $product->selling_price;

        return match ($rule->discount_type) {
            'percent' => bcsub($basePrice, bcmul($basePrice, bcdiv((string) $rule->discount_value, '100', 6), 4), 4),
            'fixed_amount' => bcsub($basePrice, (string) $rule->discount_value, 4),
            'fixed_price' => (string) $rule->discount_value,
            default => $basePrice,
        };
    }

    /**
     * Calculate wholesale vs retail margin.
     */
    public function calculateMargins(int $productId): array
    {
        $product = Product::find($productId);

        if (!$product) {
            return [];
        }

        $purchasePrice = (string) ($product->purchase_price ?? 0);
        $retailPrice = (string) $product->selling_price;

        // Get wholesale price if exists
        $wholesalePrice = $retailPrice;
        $wholesalePriceList = PriceList::where('organization_id', $product->organization_id)
            ->whereHas('customerGroup', fn($q) => $q->where('wholesale', true))
            ->active()
            ->first();

        if ($wholesalePriceList) {
            $item = $wholesalePriceList->getPrice($productId, 1);
            if ($item) {
                $wholesalePrice = $item->getEffectivePrice();
            }
        }

        $retailMargin = $purchasePrice > 0
            ? bcmul(bcdiv(bcsub($retailPrice, $purchasePrice, 4), $purchasePrice, 6), '100', 2)
            : '0';

        $wholesaleMargin = $purchasePrice > 0
            ? bcmul(bcdiv(bcsub($wholesalePrice, $purchasePrice, 4), $purchasePrice, 6), '100', 2)
            : '0';

        return [
            'purchase_price' => $purchasePrice,
            'retail_price' => $retailPrice,
            'wholesale_price' => $wholesalePrice,
            'retail_margin_percent' => $retailMargin,
            'wholesale_margin_percent' => $wholesaleMargin,
            'retail_profit' => bcsub($retailPrice, $purchasePrice, 4),
            'wholesale_profit' => bcsub($wholesalePrice, $purchasePrice, 4),
        ];
    }
}

/**
 * Price calculation result.
 */
class PriceResult
{
    public function __construct(
        public readonly string $unitPrice,
        public readonly float $quantity,
        public readonly string $discountPercent,
        public readonly string $discountAmount,
        public readonly string $subtotal,
        public readonly bool $isTaxInclusive,
        public readonly string $source,
        public readonly ?int $priceListId,
        public readonly bool $tiered = false,
        public readonly ?string $originalPrice = null,
        public readonly ?float $tierMinQty = null,
        public readonly ?int $bulkRuleId = null
    ) {}

    public function toArray(): array
    {
        return [
            'unit_price' => $this->unitPrice,
            'quantity' => $this->quantity,
            'discount_percent' => $this->discountPercent,
            'discount_amount' => $this->discountAmount,
            'subtotal' => $this->subtotal,
            'is_tax_inclusive' => $this->isTaxInclusive,
            'source' => $this->source,
            'price_list_id' => $this->priceListId,
            'tiered' => $this->tiered,
            'original_price' => $this->originalPrice,
            'tier_min_qty' => $this->tierMinQty,
        ];
    }
}
