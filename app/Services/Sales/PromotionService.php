<?php

declare(strict_types=1);

namespace App\Services\Sales;

use App\Models\Sales\CouponCode;
use App\Models\Sales\Promotion;
use Illuminate\Support\Collection;

class PromotionService
{
    /**
     * Get all applicable promotions for a cart/order.
     */
    public function getApplicablePromotions(
        int $organizationId,
        array $lineItems,
        ?int $customerId = null,
        string $orderTotal = '0',
        ?string $promoCode = null
    ): Collection {
        $promotions = collect();

        // Get auto-apply promotions
        $autoPromotions = Promotion::where('organization_id', $organizationId)
            ->valid()
            ->autoApply()
            ->orderBy('priority', 'desc')
            ->get();

        foreach ($autoPromotions as $promo) {
            if ($promo->canUse($customerId)) {
                $promotions->push($promo);
            }
        }

        // Check promo code
        if ($promoCode) {
            $codePromo = $this->validatePromoCode($organizationId, $promoCode, $customerId);
            if ($codePromo) {
                $promotions->push($codePromo);
            }
        }

        // Filter to applicable products
        $applicablePromotions = $promotions->filter(function ($promo) use ($lineItems, $orderTotal) {
            // Check if any line item matches
            foreach ($lineItems as $item) {
                if ($promo->isValidForProduct($item['product_id'])) {
                    return true;
                }
            }

            // Order-level promotions
            if ($promo->apply_to === Promotion::APPLY_ORDER) {
                if ($promo->min_order_amount) {
                    return bccomp($orderTotal, (string) $promo->min_order_amount, 4) >= 0;
                }
                return true;
            }

            return false;
        });

        // Handle exclusive promotions
        return $this->resolveExclusive($applicablePromotions);
    }

    /**
     * Calculate discounts for a cart/order.
     */
    public function calculateDiscounts(
        int $organizationId,
        array $lineItems,
        ?int $customerId = null,
        ?string $promoCode = null
    ): DiscountCalculation {
        $orderTotal = '0';
        foreach ($lineItems as $item) {
            $lineTotal = bcmul($item['quantity'] ?? '1', $item['unit_price'] ?? '0', 4);
            $orderTotal = bcadd($orderTotal, $lineTotal, 4);
        }

        $promotions = $this->getApplicablePromotions(
            $organizationId,
            $lineItems,
            $customerId,
            $orderTotal,
            $promoCode
        );

        $lineDiscounts = [];
        $orderDiscounts = [];
        $totalLineDiscount = '0';
        $totalOrderDiscount = '0';

        foreach ($promotions as $promo) {
            if ($promo->apply_to === Promotion::APPLY_LINE) {
                // Apply to each applicable line
                foreach ($lineItems as $index => $item) {
                    if (!$promo->isValidForProduct($item['product_id'])) {
                        continue;
                    }

                    $lineTotal = bcmul($item['quantity'] ?? '1', $item['unit_price'] ?? '0', 4);
                    $result = $promo->calculateDiscount($lineTotal, (float) ($item['quantity'] ?? 1));

                    if ($result->applied) {
                        $lineDiscounts[$index][] = [
                            'promotion_id' => $promo->id,
                            'promotion_name' => $promo->name,
                            'discount_amount' => $result->discountAmount,
                        ];
                        $totalLineDiscount = bcadd($totalLineDiscount, $result->discountAmount, 4);
                    }
                }
            } elseif ($promo->apply_to === Promotion::APPLY_ORDER) {
                // Apply to order total
                $totalQty = array_sum(array_column($lineItems, 'quantity'));
                $result = $promo->calculateDiscount($orderTotal, $totalQty);

                if ($result->applied) {
                    $orderDiscounts[] = [
                        'promotion_id' => $promo->id,
                        'promotion_name' => $promo->name,
                        'discount_amount' => $result->discountAmount,
                    ];
                    $totalOrderDiscount = bcadd($totalOrderDiscount, $result->discountAmount, 4);
                }
            }
        }

        $totalDiscount = bcadd($totalLineDiscount, $totalOrderDiscount, 4);
        $finalTotal = bcsub($orderTotal, $totalDiscount, 4);

        return new DiscountCalculation(
            lineDiscounts: $lineDiscounts,
            orderDiscounts: $orderDiscounts,
            totalLineDiscount: $totalLineDiscount,
            totalOrderDiscount: $totalOrderDiscount,
            totalDiscount: $totalDiscount,
            originalTotal: $orderTotal,
            finalTotal: $finalTotal,
            appliedPromotions: $promotions->pluck('id')->toArray()
        );
    }

    /**
     * Validate a promo code.
     */
    public function validatePromoCode(int $organizationId, string $code, ?int $customerId = null): ?Promotion
    {
        // Check promotion code
        $promotion = Promotion::where('organization_id', $organizationId)
            ->where('code', $code)
            ->valid()
            ->first();

        if ($promotion && $promotion->canUse($customerId)) {
            return $promotion;
        }

        // Check coupon codes
        $coupon = CouponCode::where('code', $code)
            ->where('is_active', true)
            ->whereHas('promotion', function ($q) use ($organizationId) {
                $q->where('organization_id', $organizationId)->valid();
            })
            ->first();

        if ($coupon) {
            if ($coupon->max_uses && $coupon->current_uses >= $coupon->max_uses) {
                return null;
            }

            if ($coupon->expires_at && $coupon->expires_at->isPast()) {
                return null;
            }

            if ($coupon->assigned_to && $coupon->assigned_to !== $customerId) {
                return null;
            }

            $promotion = $coupon->promotion;
            if ($promotion->canUse($customerId)) {
                return $promotion;
            }
        }

        return null;
    }

    /**
     * Record promotion usage after order completion.
     */
    public function recordUsage(
        array $appliedPromotionIds,
        int $orderId,
        string $orderType,
        ?int $customerId,
        array $discountAmounts
    ): void {
        foreach ($appliedPromotionIds as $promoId) {
            $promo = Promotion::find($promoId);
            if ($promo) {
                $amount = $discountAmounts[$promoId] ?? '0';
                $promo->recordUsage($orderId, $orderType, $customerId, $amount);
            }
        }
    }

    /**
     * Resolve exclusive promotions (only one can apply).
     */
    protected function resolveExclusive(Collection $promotions): Collection
    {
        $exclusivePromos = $promotions->where('is_exclusive', true);

        if ($exclusivePromos->isEmpty()) {
            return $promotions;
        }

        // Get highest priority exclusive promotion
        $bestExclusive = $exclusivePromos->sortByDesc('priority')->first();

        // Keep non-exclusive stackable promotions + best exclusive
        return $promotions
            ->filter(fn($p) => !$p->is_exclusive || $p->id === $bestExclusive->id)
            ->filter(fn($p) => $p->is_stackable || $p->is_exclusive);
    }

    /**
     * Generate unique coupon codes.
     */
    public function generateCouponCodes(
        int $promotionId,
        int $quantity,
        ?string $prefix = null,
        int $length = 8
    ): array {
        $codes = [];

        for ($i = 0; $i < $quantity; $i++) {
            $code = $this->generateUniqueCode($prefix, $length);

            CouponCode::create([
                'promotion_id' => $promotionId,
                'code' => $code,
                'is_active' => true,
            ]);

            $codes[] = $code;
        }

        return $codes;
    }

    protected function generateUniqueCode(?string $prefix, int $length): string
    {
        do {
            $code = $prefix ?? '';
            $code .= strtoupper(substr(str_shuffle('ABCDEFGHJKLMNPQRSTUVWXYZ23456789'), 0, $length));
        } while (CouponCode::where('code', $code)->exists());

        return $code;
    }

    /**
     * Get promotion analytics.
     */
    public function getAnalytics(int $promotionId): array
    {
        $promo = Promotion::with('usages')->find($promotionId);

        if (!$promo) {
            return [];
        }

        $usages = $promo->usages;

        return [
            'total_uses' => $usages->count(),
            'total_discount_given' => $usages->sum('discount_amount'),
            'unique_customers' => $usages->unique('contact_id')->count(),
            'average_discount' => $usages->count() > 0 ? $usages->avg('discount_amount') : 0,
            'uses_by_day' => $usages->groupBy(fn($u) => $u->created_at->format('Y-m-d'))
                ->map->count()
                ->toArray(),
            'remaining_uses' => $promo->max_uses ? max(0, $promo->max_uses - $promo->current_uses) : null,
        ];
    }
}

class DiscountCalculation
{
    public function __construct(
        public readonly array $lineDiscounts,
        public readonly array $orderDiscounts,
        public readonly string $totalLineDiscount,
        public readonly string $totalOrderDiscount,
        public readonly string $totalDiscount,
        public readonly string $originalTotal,
        public readonly string $finalTotal,
        public readonly array $appliedPromotions
    ) {}

    public function toArray(): array
    {
        return [
            'line_discounts' => $this->lineDiscounts,
            'order_discounts' => $this->orderDiscounts,
            'total_line_discount' => $this->totalLineDiscount,
            'total_order_discount' => $this->totalOrderDiscount,
            'total_discount' => $this->totalDiscount,
            'original_total' => $this->originalTotal,
            'final_total' => $this->finalTotal,
            'applied_promotions' => $this->appliedPromotions,
        ];
    }
}
