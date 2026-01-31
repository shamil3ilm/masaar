<?php

declare(strict_types=1);

namespace App\Domains\Licensing\Enums;

/**
 * License tiers with their limits and features.
 */
enum LicenseTier: string
{
    case Starter = 'starter';
    case Professional = 'professional';
    case Enterprise = 'enterprise';
    case Unlimited = 'unlimited';

    /**
     * Get default limits for this tier.
     */
    public function getDefaults(): array
    {
        return match ($this) {
            self::Starter => [
                'max_invoices_per_month' => 100,
                'max_organizations' => 1,
                'max_api_calls_per_minute' => 30,
                'max_api_calls_per_day' => 5000,
                'offline_mode_enabled' => false,
                'multi_tenant_enabled' => false,
                'webhook_enabled' => true,
            ],
            self::Professional => [
                'max_invoices_per_month' => 1000,
                'max_organizations' => 5,
                'max_api_calls_per_minute' => 60,
                'max_api_calls_per_day' => 20000,
                'offline_mode_enabled' => true,
                'multi_tenant_enabled' => false,
                'webhook_enabled' => true,
            ],
            self::Enterprise => [
                'max_invoices_per_month' => 10000,
                'max_organizations' => 50,
                'max_api_calls_per_minute' => 120,
                'max_api_calls_per_day' => 100000,
                'offline_mode_enabled' => true,
                'multi_tenant_enabled' => true,
                'webhook_enabled' => true,
            ],
            self::Unlimited => [
                'max_invoices_per_month' => PHP_INT_MAX,
                'max_organizations' => PHP_INT_MAX,
                'max_api_calls_per_minute' => 300,
                'max_api_calls_per_day' => PHP_INT_MAX,
                'offline_mode_enabled' => true,
                'multi_tenant_enabled' => true,
                'webhook_enabled' => true,
            ],
        };
    }

    /**
     * Get display name.
     */
    public function getDisplayName(): string
    {
        return match ($this) {
            self::Starter => 'Starter',
            self::Professional => 'Professional',
            self::Enterprise => 'Enterprise',
            self::Unlimited => 'Unlimited',
        };
    }

    /**
     * Get tier price (SAR/month) - for reference.
     */
    public function getMonthlyPrice(): int
    {
        return match ($this) {
            self::Starter => 500,
            self::Professional => 2000,
            self::Enterprise => 5000,
            self::Unlimited => 0, // Custom pricing
        };
    }

    /**
     * Get default scopes for this tier.
     */
    public function getDefaultScopes(): array
    {
        return ApiScope::getDefaultsForTier($this);
    }

    /**
     * Get default features for this tier.
     */
    public function getDefaultFeatures(): array
    {
        return match ($this) {
            self::Starter => [
                'simplified_invoices',
                'basic_reports',
            ],
            self::Professional => [
                'simplified_invoices',
                'standard_invoices',
                'credit_notes',
                'debit_notes',
                'advanced_reports',
                'webhook_notifications',
            ],
            self::Enterprise, self::Unlimited => [
                'simplified_invoices',
                'standard_invoices',
                'credit_notes',
                'debit_notes',
                'advanced_reports',
                'webhook_notifications',
                'priority_support',
                'custom_integrations',
                'batch_processing',
                'dedicated_support',
                'sla_guarantee',
            ],
        };
    }
}
