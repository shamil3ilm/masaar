<?php

declare(strict_types=1);

namespace App\Models\Core;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class OrganizationSubscription extends Model
{
    protected $fillable = [
        'organization_id',
        'plan_id',
        'status',
        'billing_cycle',
        'started_at',
        'expires_at',
        'cancelled_at',
        'trial_ends_at',
        'custom_limits',
        'addons',
        'payment_method',
        'external_subscription_id',
    ];

    protected $casts = [
        'started_at' => 'date',
        'expires_at' => 'date',
        'cancelled_at' => 'date',
        'trial_ends_at' => 'date',
        'custom_limits' => 'array',
        'addons' => 'array',
    ];

    // Statuses
    public const STATUS_ACTIVE = 'active';
    public const STATUS_TRIAL = 'trial';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_PAST_DUE = 'past_due';
    public const STATUS_SUSPENDED = 'suspended';

    // Billing cycles
    public const CYCLE_MONTHLY = 'monthly';
    public const CYCLE_YEARLY = 'yearly';

    // Relationships

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'plan_id');
    }

    // Scopes

    public function scopeActive($query)
    {
        return $query->whereIn('status', [self::STATUS_ACTIVE, self::STATUS_TRIAL]);
    }

    public function scopeForOrganization($query, int $organizationId)
    {
        return $query->where('organization_id', $organizationId);
    }

    // Helpers

    public function isActive(): bool
    {
        return in_array($this->status, [self::STATUS_ACTIVE, self::STATUS_TRIAL]);
    }

    public function isTrial(): bool
    {
        return $this->status === self::STATUS_TRIAL;
    }

    public function isExpired(): bool
    {
        if ($this->expires_at && $this->expires_at->isPast()) {
            return true;
        }

        if ($this->isTrial() && $this->trial_ends_at?->isPast()) {
            return true;
        }

        return $this->status === self::STATUS_EXPIRED;
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    public function daysUntilExpiry(): ?int
    {
        if (!$this->expires_at) {
            return null;
        }

        return max(0, Carbon::now()->diffInDays($this->expires_at, false));
    }

    public function trialDaysRemaining(): ?int
    {
        if (!$this->trial_ends_at) {
            return null;
        }

        return max(0, Carbon::now()->diffInDays($this->trial_ends_at, false));
    }

    /**
     * Check if organization has a specific feature.
     */
    public function hasFeature(string $featureCode): bool
    {
        // Check if feature is in addons
        if (isset($this->addons['features']) && in_array($featureCode, $this->addons['features'])) {
            return true;
        }

        // Check plan features
        return $this->plan?->hasFeature($featureCode) ?? false;
    }

    /**
     * Get limit value (from custom limits or plan).
     */
    public function getLimit(string $limitKey): ?int
    {
        // Check custom limits first
        if (isset($this->custom_limits[$limitKey])) {
            return $this->custom_limits[$limitKey];
        }

        // Fall back to plan limits
        return $this->plan?->$limitKey ?? null;
    }

    /**
     * Get max users allowed.
     */
    public function getMaxUsers(): ?int
    {
        return $this->getLimit('max_users');
    }

    /**
     * Get max branches allowed.
     */
    public function getMaxBranches(): ?int
    {
        return $this->getLimit('max_branches');
    }

    /**
     * Get max products allowed.
     */
    public function getMaxProducts(): ?int
    {
        return $this->getLimit('max_products');
    }

    /**
     * Get max invoices per month.
     */
    public function getMaxInvoicesPerMonth(): ?int
    {
        return $this->getLimit('max_invoices_per_month');
    }

    /**
     * Get storage limit in GB.
     */
    public function getStorageGb(): int
    {
        return $this->getLimit('storage_gb') ?? 1;
    }

    /**
     * Get current subscription for organization.
     */
    public static function getCurrentForOrganization(int $organizationId): ?self
    {
        return static::where('organization_id', $organizationId)
            ->active()
            ->with('plan')
            ->orderByDesc('created_at')
            ->first();
    }

    /**
     * Check if organization can perform action based on limits.
     */
    public function canAddUser(): bool
    {
        $max = $this->getMaxUsers();
        if ($max === null) {
            return true; // Unlimited
        }

        $currentCount = $this->organization->users()->count();
        return $currentCount < $max;
    }

    public function canAddBranch(): bool
    {
        $max = $this->getMaxBranches();
        if ($max === null) {
            return true;
        }

        $currentCount = $this->organization->branches()->count();
        return $currentCount < $max;
    }

    public function canAddProduct(): bool
    {
        $max = $this->getMaxProducts();
        if ($max === null) {
            return true;
        }

        $currentCount = $this->organization->products()->count();
        return $currentCount < $max;
    }

    public function canCreateInvoiceThisMonth(): bool
    {
        $max = $this->getMaxInvoicesPerMonth();
        if ($max === null) {
            return true;
        }

        $startOfMonth = Carbon::now()->startOfMonth();
        $currentCount = $this->organization->invoices()
            ->where('created_at', '>=', $startOfMonth)
            ->count();

        return $currentCount < $max;
    }

    /**
     * Start a new subscription.
     */
    public static function startSubscription(
        int $organizationId,
        int $planId,
        string $billingCycle = self::CYCLE_MONTHLY,
        ?int $trialDays = null
    ): self {
        // Cancel any existing active subscription
        static::where('organization_id', $organizationId)
            ->active()
            ->update(['status' => self::STATUS_CANCELLED, 'cancelled_at' => now()]);

        $expiresAt = $billingCycle === self::CYCLE_YEARLY
            ? Carbon::now()->addYear()
            : Carbon::now()->addMonth();

        return static::create([
            'organization_id' => $organizationId,
            'plan_id' => $planId,
            'status' => $trialDays ? self::STATUS_TRIAL : self::STATUS_ACTIVE,
            'billing_cycle' => $billingCycle,
            'started_at' => now(),
            'expires_at' => $expiresAt,
            'trial_ends_at' => $trialDays ? Carbon::now()->addDays($trialDays) : null,
        ]);
    }

    /**
     * Cancel subscription.
     */
    public function cancel(bool $immediate = false): void
    {
        $this->status = self::STATUS_CANCELLED;
        $this->cancelled_at = now();

        if ($immediate) {
            $this->expires_at = now();
        }

        $this->save();
    }

    /**
     * Renew subscription.
     */
    public function renew(): void
    {
        $this->status = self::STATUS_ACTIVE;
        $this->cancelled_at = null;

        $this->expires_at = $this->billing_cycle === self::CYCLE_YEARLY
            ? Carbon::now()->addYear()
            : Carbon::now()->addMonth();

        $this->save();
    }

    /**
     * Upgrade/Downgrade to a new plan.
     */
    public function changePlan(int $newPlanId): void
    {
        $this->plan_id = $newPlanId;
        $this->save();
    }
}
