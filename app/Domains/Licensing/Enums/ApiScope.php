<?php

declare(strict_types=1);

namespace App\Domains\Licensing\Enums;

/**
 * API Scopes for fine-grained authorization.
 *
 * These scopes control what operations an API key can perform.
 * ZATCA-compliant: scopes only affect authorization, never data mutation.
 */
enum ApiScope: string
{
    // Invoice operations
    case InvoiceSubmit = 'invoice.submit';
    case InvoiceRead = 'invoice.read';
    case InvoiceCancel = 'invoice.cancel';
    case InvoiceBatch = 'invoice.batch';

    // Compliance operations
    case ComplianceStatus = 'compliance.status';
    case ComplianceCertificate = 'compliance.certificate';
    case ComplianceOnboarding = 'compliance.onboarding';

    // Organization operations
    case OrganizationRead = 'organization.read';
    case OrganizationWrite = 'organization.write';

    // Webhook operations
    case WebhookManage = 'webhook.manage';

    // Reporting
    case ReportsRead = 'reports.read';
    case ReportsExport = 'reports.export';

    /**
     * Get scopes implied by this scope.
     */
    public function getImpliedScopes(): array
    {
        return match ($this) {
            self::InvoiceCancel => [self::InvoiceRead],
            self::InvoiceBatch => [self::InvoiceSubmit],
            self::ComplianceOnboarding => [self::ComplianceCertificate],
            self::OrganizationWrite => [self::OrganizationRead],
            self::ReportsExport => [self::ReportsRead],
            default => [],
        };
    }

    /**
     * Check if this scope requires production environment.
     */
    public function requiresProduction(): bool
    {
        // Currently no scopes require production
        // This can be used for scopes that should only work in live mode
        return false;
    }

    /**
     * Get the category for this scope.
     */
    public function getCategory(): string
    {
        return match ($this) {
            self::InvoiceSubmit, self::InvoiceRead, self::InvoiceCancel, self::InvoiceBatch => 'invoice',
            self::ComplianceStatus, self::ComplianceCertificate, self::ComplianceOnboarding => 'compliance',
            self::OrganizationRead, self::OrganizationWrite => 'organization',
            self::WebhookManage => 'webhook',
            self::ReportsRead, self::ReportsExport => 'reports',
        };
    }

    /**
     * Get description for this scope.
     */
    public function getDescription(): string
    {
        return match ($this) {
            self::InvoiceSubmit => 'Submit invoices for clearance/reporting',
            self::InvoiceRead => 'Read invoice details and status',
            self::InvoiceCancel => 'Cancel/void invoices (credit notes)',
            self::InvoiceBatch => 'Submit batch invoices',
            self::ComplianceStatus => 'Check ZATCA compliance status',
            self::ComplianceCertificate => 'Manage ZATCA certificates',
            self::ComplianceOnboarding => 'Complete ZATCA onboarding',
            self::OrganizationRead => 'Read organization details',
            self::OrganizationWrite => 'Create/update organizations',
            self::WebhookManage => 'Manage webhook endpoints',
            self::ReportsRead => 'Access usage and compliance reports',
            self::ReportsExport => 'Export reports in various formats',
        };
    }

    /**
     * Get default scopes for a tier.
     */
    public static function getDefaultsForTier(LicenseTier $tier): array
    {
        $basic = [
            self::InvoiceSubmit->value,
            self::InvoiceRead->value,
            self::ComplianceStatus->value,
            self::OrganizationRead->value,
        ];

        $professional = [
            ...$basic,
            self::InvoiceCancel->value,
            self::ComplianceCertificate->value,
            self::OrganizationWrite->value,
            self::WebhookManage->value,
            self::ReportsRead->value,
        ];

        $enterprise = [
            ...$professional,
            self::InvoiceBatch->value,
            self::ComplianceOnboarding->value,
            self::ReportsExport->value,
        ];

        return match ($tier) {
            LicenseTier::Starter => $basic,
            LicenseTier::Professional => $professional,
            LicenseTier::Enterprise, LicenseTier::Unlimited => $enterprise,
        };
    }

    /**
     * Expand scopes to include implied scopes.
     */
    public static function expandScopes(array $scopes): array
    {
        $expanded = [];

        foreach ($scopes as $scopeValue) {
            $scope = self::tryFrom($scopeValue);
            if ($scope) {
                $expanded[] = $scopeValue;
                foreach ($scope->getImpliedScopes() as $implied) {
                    $expanded[] = $implied->value;
                }
            }
        }

        return array_unique($expanded);
    }
}
