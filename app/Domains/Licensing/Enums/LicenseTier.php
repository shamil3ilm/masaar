<?php

declare(strict_types=1);

namespace App\Domains\Licensing\Enums;

use App\Domains\Licensing\Models\License;

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
     *
     * The allowances - invoices a month and API calls a day - are not the
     * tier's: every tier starts at the platform's default (licensing.allowances,
     * unlimited for now) and a platform admin sets a customer's own. What a
     * tier still decides is the per-minute call cap, a safety limit, and how
     * many organizations a licence may serve.
     */
    public function getDefaults(): array
    {
        return [...$this->limits(), ...self::allowances()];
    }

    /**
     * The platform's default allowances, as the columns hold them: -1, or
     * anything past the ceiling, is License::UNLIMITED.
     *
     * @return array{invoices_per_month: int, calls_per_day: int}
     */
    public static function allowances(): array
    {
        $stored = function (string $allowance): int {
            $value = (int) config('licensing.allowances.'.$allowance, -1);

            return $value < 0 || $value > License::UNLIMITED ? License::UNLIMITED : $value;
        };

        return [
            'invoices_per_month' => $stored('invoices_per_month'),
            'calls_per_day' => $stored('calls_per_day'),
        ];
    }

    /**
     * The tier's own limits, before the allowances.
     *
     * @return array<string, int|bool>
     */
    private function limits(): array
    {
        return match ($this) {
            self::Starter => [
                'max_organizations' => 1,
                'calls_per_min' => 30,
                'offline_mode' => false,
                'multi_tenant' => false,
                'webhook_enabled' => true,
            ],
            self::Professional => [
                'max_organizations' => 5,
                'calls_per_min' => 60,
                'offline_mode' => true,
                'multi_tenant' => false,
                'webhook_enabled' => true,
            ],
            self::Enterprise => [
                'max_organizations' => 50,
                'calls_per_min' => 120,
                'offline_mode' => true,
                'multi_tenant' => true,
                'webhook_enabled' => true,
            ],
            self::Unlimited => [
                'max_organizations' => License::UNLIMITED,
                'calls_per_min' => 300,
                'offline_mode' => true,
                'multi_tenant' => true,
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
        // config/billing.php, so a price change is a setting, not a release.
        return (int) config('billing.prices.'.$this->value, 0);
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
