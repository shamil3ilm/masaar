<?php

declare(strict_types=1);

namespace App\Models\Core;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SubscriptionPlan extends Model
{
    protected $fillable = [
        'code',
        'name',
        'description',
        'monthly_price',
        'yearly_price',
        'currency_code',
        'max_users',
        'max_branches',
        'max_products',
        'max_invoices_per_month',
        'storage_gb',
        'features',
        'limits',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'monthly_price' => 'decimal:2',
        'yearly_price' => 'decimal:2',
        'features' => 'array',
        'limits' => 'array',
        'is_active' => 'boolean',
    ];

    // Plan codes
    public const PLAN_FREE = 'free';
    public const PLAN_STARTER = 'starter';
    public const PLAN_PROFESSIONAL = 'professional';
    public const PLAN_ENTERPRISE = 'enterprise';

    // Feature codes
    public const FEATURE_MULTI_BRANCH = 'multi_branch';
    public const FEATURE_MULTI_WAREHOUSE = 'multi_warehouse';
    public const FEATURE_MULTI_CURRENCY = 'multi_currency';
    public const FEATURE_BATCH_TRACKING = 'batch_tracking';
    public const FEATURE_SERIAL_TRACKING = 'serial_tracking';
    public const FEATURE_ADVANCED_REPORTS = 'advanced_reports';
    public const FEATURE_CUSTOM_REPORTS = 'custom_reports';
    public const FEATURE_API_ACCESS = 'api_access';
    public const FEATURE_WEBHOOKS = 'webhooks';
    public const FEATURE_CUSTOM_BRANDING = 'custom_branding';
    public const FEATURE_WHITE_LABEL = 'white_label';
    public const FEATURE_PRIORITY_SUPPORT = 'priority_support';
    public const FEATURE_DEDICATED_SUPPORT = 'dedicated_support';
    public const FEATURE_DATA_EXPORT = 'data_export';
    public const FEATURE_AUDIT_LOG = 'audit_log';
    public const FEATURE_ADVANCED_PERMISSIONS = 'advanced_permissions';
    public const FEATURE_CUSTOM_FIELDS = 'custom_fields';
    public const FEATURE_DASHBOARD_CUSTOMIZATION = 'dashboard_customization';
    public const FEATURE_EMAIL_TEMPLATES = 'email_templates';
    public const FEATURE_SMS_NOTIFICATIONS = 'sms_notifications';
    public const FEATURE_RECURRING_INVOICES = 'recurring_invoices';
    public const FEATURE_CREDIT_NOTES = 'credit_notes';
    public const FEATURE_PURCHASE_MODULE = 'purchase_module';
    public const FEATURE_HR_MODULE = 'hr_module';
    public const FEATURE_MANUFACTURING_MODULE = 'manufacturing_module';
    public const FEATURE_CRM_MODULE = 'crm_module';
    public const FEATURE_POS_MODULE = 'pos_module';
    public const FEATURE_COMPLIANCE_INTEGRATION = 'compliance_integration';
    public const FEATURE_ECOMMERCE_INTEGRATION = 'ecommerce_integration';
    public const FEATURE_ACCOUNTING_INTEGRATION = 'accounting_integration';

    // Default plans
    public const DEFAULT_PLANS = [
        self::PLAN_FREE => [
            'name' => 'Free',
            'description' => 'Perfect for getting started',
            'monthly_price' => 0,
            'yearly_price' => 0,
            'max_users' => 1,
            'max_branches' => 1,
            'max_products' => 100,
            'max_invoices_per_month' => 50,
            'storage_gb' => 1,
            'features' => [
                self::FEATURE_CREDIT_NOTES,
                self::FEATURE_DATA_EXPORT,
            ],
        ],
        self::PLAN_STARTER => [
            'name' => 'Starter',
            'description' => 'For small businesses',
            'monthly_price' => 29,
            'yearly_price' => 290,
            'max_users' => 3,
            'max_branches' => 1,
            'max_products' => 500,
            'max_invoices_per_month' => 200,
            'storage_gb' => 5,
            'features' => [
                self::FEATURE_CREDIT_NOTES,
                self::FEATURE_DATA_EXPORT,
                self::FEATURE_RECURRING_INVOICES,
                self::FEATURE_EMAIL_TEMPLATES,
                self::FEATURE_AUDIT_LOG,
                self::FEATURE_PURCHASE_MODULE,
                self::FEATURE_CUSTOM_BRANDING,
            ],
        ],
        self::PLAN_PROFESSIONAL => [
            'name' => 'Professional',
            'description' => 'For growing businesses',
            'monthly_price' => 79,
            'yearly_price' => 790,
            'max_users' => 10,
            'max_branches' => 3,
            'max_products' => null, // Unlimited
            'max_invoices_per_month' => null,
            'storage_gb' => 25,
            'features' => [
                self::FEATURE_CREDIT_NOTES,
                self::FEATURE_DATA_EXPORT,
                self::FEATURE_RECURRING_INVOICES,
                self::FEATURE_EMAIL_TEMPLATES,
                self::FEATURE_AUDIT_LOG,
                self::FEATURE_PURCHASE_MODULE,
                self::FEATURE_CUSTOM_BRANDING,
                self::FEATURE_MULTI_BRANCH,
                self::FEATURE_MULTI_WAREHOUSE,
                self::FEATURE_MULTI_CURRENCY,
                self::FEATURE_BATCH_TRACKING,
                self::FEATURE_ADVANCED_REPORTS,
                self::FEATURE_API_ACCESS,
                self::FEATURE_ADVANCED_PERMISSIONS,
                self::FEATURE_CUSTOM_FIELDS,
                self::FEATURE_DASHBOARD_CUSTOMIZATION,
                self::FEATURE_HR_MODULE,
                self::FEATURE_CRM_MODULE,
                self::FEATURE_COMPLIANCE_INTEGRATION,
                self::FEATURE_PRIORITY_SUPPORT,
            ],
        ],
        self::PLAN_ENTERPRISE => [
            'name' => 'Enterprise',
            'description' => 'For large organizations',
            'monthly_price' => 199,
            'yearly_price' => 1990,
            'max_users' => null, // Unlimited
            'max_branches' => null,
            'max_products' => null,
            'max_invoices_per_month' => null,
            'storage_gb' => 100,
            'features' => [
                // All features
                self::FEATURE_CREDIT_NOTES,
                self::FEATURE_DATA_EXPORT,
                self::FEATURE_RECURRING_INVOICES,
                self::FEATURE_EMAIL_TEMPLATES,
                self::FEATURE_AUDIT_LOG,
                self::FEATURE_PURCHASE_MODULE,
                self::FEATURE_CUSTOM_BRANDING,
                self::FEATURE_MULTI_BRANCH,
                self::FEATURE_MULTI_WAREHOUSE,
                self::FEATURE_MULTI_CURRENCY,
                self::FEATURE_BATCH_TRACKING,
                self::FEATURE_SERIAL_TRACKING,
                self::FEATURE_ADVANCED_REPORTS,
                self::FEATURE_CUSTOM_REPORTS,
                self::FEATURE_API_ACCESS,
                self::FEATURE_WEBHOOKS,
                self::FEATURE_WHITE_LABEL,
                self::FEATURE_ADVANCED_PERMISSIONS,
                self::FEATURE_CUSTOM_FIELDS,
                self::FEATURE_DASHBOARD_CUSTOMIZATION,
                self::FEATURE_SMS_NOTIFICATIONS,
                self::FEATURE_HR_MODULE,
                self::FEATURE_MANUFACTURING_MODULE,
                self::FEATURE_CRM_MODULE,
                self::FEATURE_POS_MODULE,
                self::FEATURE_COMPLIANCE_INTEGRATION,
                self::FEATURE_ECOMMERCE_INTEGRATION,
                self::FEATURE_ACCOUNTING_INTEGRATION,
                self::FEATURE_DEDICATED_SUPPORT,
            ],
        ],
    ];

    // Relationships

    public function subscriptions(): HasMany
    {
        return $this->hasMany(OrganizationSubscription::class, 'plan_id');
    }

    // Scopes

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('monthly_price');
    }

    // Helpers

    public function hasFeature(string $featureCode): bool
    {
        return in_array($featureCode, $this->features ?? []);
    }

    public function getYearlySavings(): float
    {
        if ($this->monthly_price <= 0) {
            return 0;
        }

        $yearlyIfMonthly = $this->monthly_price * 12;
        return $yearlyIfMonthly - $this->yearly_price;
    }

    public function getYearlySavingsPercentage(): float
    {
        if ($this->monthly_price <= 0) {
            return 0;
        }

        $yearlyIfMonthly = $this->monthly_price * 12;
        return round((($yearlyIfMonthly - $this->yearly_price) / $yearlyIfMonthly) * 100);
    }

    public static function getFeatureList(): array
    {
        return [
            self::FEATURE_MULTI_BRANCH => 'Multi-Branch Support',
            self::FEATURE_MULTI_WAREHOUSE => 'Multi-Warehouse Management',
            self::FEATURE_MULTI_CURRENCY => 'Multi-Currency Support',
            self::FEATURE_BATCH_TRACKING => 'Batch/Lot Tracking',
            self::FEATURE_SERIAL_TRACKING => 'Serial Number Tracking',
            self::FEATURE_ADVANCED_REPORTS => 'Advanced Reports',
            self::FEATURE_CUSTOM_REPORTS => 'Custom Report Builder',
            self::FEATURE_API_ACCESS => 'API Access',
            self::FEATURE_WEBHOOKS => 'Webhooks',
            self::FEATURE_CUSTOM_BRANDING => 'Custom Branding',
            self::FEATURE_WHITE_LABEL => 'White Label',
            self::FEATURE_PRIORITY_SUPPORT => 'Priority Support',
            self::FEATURE_DEDICATED_SUPPORT => 'Dedicated Account Manager',
            self::FEATURE_DATA_EXPORT => 'Data Export',
            self::FEATURE_AUDIT_LOG => 'Audit Log',
            self::FEATURE_ADVANCED_PERMISSIONS => 'Advanced Permissions',
            self::FEATURE_CUSTOM_FIELDS => 'Custom Fields',
            self::FEATURE_DASHBOARD_CUSTOMIZATION => 'Dashboard Customization',
            self::FEATURE_EMAIL_TEMPLATES => 'Email Templates',
            self::FEATURE_SMS_NOTIFICATIONS => 'SMS Notifications',
            self::FEATURE_RECURRING_INVOICES => 'Recurring Invoices',
            self::FEATURE_CREDIT_NOTES => 'Credit Notes',
            self::FEATURE_PURCHASE_MODULE => 'Purchase Module',
            self::FEATURE_HR_MODULE => 'HR & Payroll Module',
            self::FEATURE_MANUFACTURING_MODULE => 'Manufacturing Module',
            self::FEATURE_CRM_MODULE => 'CRM Module',
            self::FEATURE_POS_MODULE => 'Point of Sale Module',
            self::FEATURE_COMPLIANCE_INTEGRATION => 'Tax Compliance (ZATCA/GST)',
            self::FEATURE_ECOMMERCE_INTEGRATION => 'E-commerce Integration',
            self::FEATURE_ACCOUNTING_INTEGRATION => 'Accounting Integration',
        ];
    }
}
