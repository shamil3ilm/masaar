<?php

declare(strict_types=1);

namespace App\Models\Core;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;

class FeatureFlag extends Model
{
    protected $fillable = [
        'organization_id',
        'feature',
        'is_enabled',
        'config',
        'enabled_at',
        'disabled_at',
    ];

    protected $casts = [
        'is_enabled' => 'boolean',
        'config' => 'array',
        'enabled_at' => 'datetime',
        'disabled_at' => 'datetime',
    ];

    private const CACHE_TTL = 3600;

    // Available feature flags
    public const FEATURES = [
        // Sales module features
        'sales.quotations' => 'Enable quotations workflow',
        'sales.sales_orders' => 'Enable sales orders',
        'sales.credit_notes' => 'Enable credit notes',
        'sales.recurring_invoices' => 'Enable recurring invoices',
        'sales.price_lists' => 'Enable multiple price lists',
        'sales.discount_approval' => 'Require approval for discounts above threshold',

        // Purchase module features
        'purchase.purchase_orders' => 'Enable purchase orders',
        'purchase.debit_notes' => 'Enable debit notes',
        'purchase.vendor_bills' => 'Enable vendor bill management',
        'purchase.three_way_match' => 'Enable PO/GRN/Bill matching',

        // Inventory features
        'inventory.warehouses' => 'Enable multiple warehouses',
        'inventory.serial_numbers' => 'Enable serial number tracking',
        'inventory.batch_numbers' => 'Enable batch/lot tracking',
        'inventory.barcode_scanning' => 'Enable barcode scanning',
        'inventory.stock_transfers' => 'Enable inter-warehouse transfers',
        'inventory.stock_adjustments' => 'Enable stock adjustments',

        // Manufacturing features
        'manufacturing.enabled' => 'Enable manufacturing module',
        'manufacturing.work_orders' => 'Enable work orders',
        'manufacturing.bom' => 'Enable bill of materials',
        'manufacturing.quality_control' => 'Enable quality control checks',

        // HR features
        'hr.attendance' => 'Enable attendance tracking',
        'hr.leave_management' => 'Enable leave management',
        'hr.payroll' => 'Enable payroll processing',
        'hr.employee_self_service' => 'Enable employee self-service portal',
        'hr.performance_reviews' => 'Enable performance reviews',

        // CRM features
        'crm.enabled' => 'Enable CRM module',
        'crm.leads' => 'Enable lead management',
        'crm.opportunities' => 'Enable opportunity tracking',
        'crm.campaigns' => 'Enable marketing campaigns',

        // Accounting features
        'accounting.multi_currency' => 'Enable multi-currency support',
        'accounting.cost_centers' => 'Enable cost center tracking',
        'accounting.budgets' => 'Enable budget management',
        'accounting.bank_reconciliation' => 'Enable bank reconciliation',

        // Compliance features
        'compliance.zatca' => 'Enable ZATCA e-invoicing (Saudi Arabia)',
        'compliance.fta' => 'Enable FTA e-invoicing (UAE)',
        'compliance.gst' => 'Enable GST compliance (India)',
        'compliance.eway_bill' => 'Enable E-way bill (India)',

        // General features
        'general.audit_trail' => 'Enable detailed audit trail',
        'general.approval_workflows' => 'Enable approval workflows',
        'general.notifications' => 'Enable notifications',
        'general.api_access' => 'Enable API access',
        'general.bulk_operations' => 'Enable bulk import/export',
        'general.custom_fields' => 'Enable custom fields',
    ];

    // Relationships

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    // Scopes

    public function scopeEnabled($query)
    {
        return $query->where('is_enabled', true);
    }

    public function scopeDisabled($query)
    {
        return $query->where('is_enabled', false);
    }

    public function scopeGlobal($query)
    {
        return $query->whereNull('organization_id');
    }

    public function scopeForOrganization($query, int $organizationId)
    {
        return $query->where('organization_id', $organizationId);
    }

    // Instance helpers

    public function isGlobal(): bool
    {
        return $this->organization_id === null;
    }

    public function getConfigValue(string $key, mixed $default = null): mixed
    {
        return data_get($this->config, $key, $default);
    }

    public function enable(?array $config = null): self
    {
        $this->is_enabled = true;
        $this->enabled_at = now();
        $this->disabled_at = null;

        if ($config !== null) {
            $this->config = $config;
        }

        $this->save();
        $this->clearCache();
        return $this;
    }

    public function disable(): self
    {
        $this->is_enabled = false;
        $this->disabled_at = now();
        $this->save();
        $this->clearCache();
        return $this;
    }

    // Static helpers

    public static function isEnabled(int $organizationId, string $feature): bool
    {
        $cacheKey = "feature_flag:{$organizationId}:{$feature}";

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($organizationId, $feature) {
            // Check organization-specific flag first
            $flag = static::where('organization_id', $organizationId)
                ->where('feature', $feature)
                ->first();

            if ($flag) {
                return $flag->is_enabled;
            }

            // Fall back to global flag
            $globalFlag = static::whereNull('organization_id')
                ->where('feature', $feature)
                ->first();

            return $globalFlag?->is_enabled ?? false;
        });
    }

    public static function enableFeature(int $organizationId, string $feature, ?array $config = null): static
    {
        $flag = static::updateOrCreate(
            ['organization_id' => $organizationId, 'feature' => $feature],
            [
                'is_enabled' => true,
                'config' => $config,
                'enabled_at' => now(),
                'disabled_at' => null,
            ]
        );

        Cache::forget("feature_flag:{$organizationId}:{$feature}");

        return $flag;
    }

    public static function disableFeature(int $organizationId, string $feature): static
    {
        $flag = static::updateOrCreate(
            ['organization_id' => $organizationId, 'feature' => $feature],
            [
                'is_enabled' => false,
                'disabled_at' => now(),
            ]
        );

        Cache::forget("feature_flag:{$organizationId}:{$feature}");

        return $flag;
    }

    public static function getConfig(int $organizationId, string $feature): ?array
    {
        $flag = static::where('organization_id', $organizationId)
            ->where('feature', $feature)
            ->first();

        return $flag?->config;
    }

    public static function getAllForOrganization(int $organizationId): array
    {
        $orgFlags = static::where('organization_id', $organizationId)->get();
        $globalFlags = static::whereNull('organization_id')->get();

        $result = [];

        // Global flags as base
        foreach ($globalFlags as $flag) {
            $result[$flag->feature] = [
                'enabled' => $flag->is_enabled,
                'config' => $flag->config,
                'source' => 'global',
                'description' => static::FEATURES[$flag->feature] ?? null,
            ];
        }

        // Organization flags override
        foreach ($orgFlags as $flag) {
            $result[$flag->feature] = [
                'enabled' => $flag->is_enabled,
                'config' => $flag->config,
                'source' => 'organization',
                'description' => static::FEATURES[$flag->feature] ?? null,
            ];
        }

        // Add all available features with default state
        foreach (static::FEATURES as $feature => $description) {
            if (!isset($result[$feature])) {
                $result[$feature] = [
                    'enabled' => false,
                    'config' => null,
                    'source' => 'default',
                    'description' => $description,
                ];
            }
        }

        return $result;
    }

    public static function getAvailableFeatures(): array
    {
        return static::FEATURES;
    }

    protected function clearCache(): void
    {
        if ($this->organization_id) {
            Cache::forget("feature_flag:{$this->organization_id}:{$this->feature}");
        }
    }
}
