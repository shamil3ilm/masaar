<?php

declare(strict_types=1);

namespace App\Domains\Licensing\Enums;

/**
 * License Environment.
 *
 * Separates sandbox from production to prevent:
 * - Sandbox keys submitting real invoices to ZATCA
 * - Cross-environment data leakage
 * - Audit compliance issues
 */
enum LicenseEnvironment: string
{
    case Sandbox = 'sandbox';
    case Production = 'production';

    /**
     * Get the ZATCA API base URL for this environment.
     */
    public function getZatcaBaseUrl(): string
    {
        return match ($this) {
            self::Sandbox => 'https://gw-fatoora.zatca.gov.sa/e-invoicing/developer-portal',
            self::Production => 'https://gw-fatoora.zatca.gov.sa/e-invoicing/core',
        };
    }

    /**
     * Get API key prefix for this environment.
     */
    public function getApiKeyPrefix(): string
    {
        return match ($this) {
            self::Sandbox => 'cp_test_',
            self::Production => 'cp_live_',
        };
    }

    /**
     * Check if this environment allows real ZATCA submissions.
     */
    public function allowsRealSubmissions(): bool
    {
        return $this === self::Production;
    }

    /**
     * Get display label.
     */
    public function getLabel(): string
    {
        return match ($this) {
            self::Sandbox => 'Sandbox (Testing)',
            self::Production => 'Production (Live)',
        };
    }
}
