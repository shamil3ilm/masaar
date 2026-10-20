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
     * What the scope lets a key do, as the portal offers it.
     */
    public function label(): string
    {
        return match ($this) {
            self::InvoiceSubmit => __('Create, issue and file invoices'),
            self::InvoiceRead => __('Read invoices'),
            self::InvoiceCancel => __('Delete draft invoices'),
            self::InvoiceBatch => __('Send invoices in batches'),
            self::ComplianceStatus => __('Read filing status'),
            self::ComplianceCertificate => __('Read certificates'),
            self::ComplianceOnboarding => __('Onboard with the tax authority'),
            self::OrganizationRead => __('Read the organization'),
            self::OrganizationWrite => __('Change the organization'),
            self::WebhookManage => __('Manage webhooks'),
            self::ReportsRead => __('Read reports'),
            self::ReportsExport => __('Export reports'),
        };
    }

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
