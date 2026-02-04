<?php

declare(strict_types=1);

namespace App\Models\Core;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DashboardLayout extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id',
        'user_id',
        'name',
        'type',
        'widgets',
        'layout',
        'is_default',
        'is_shared',
    ];

    protected $casts = [
        'widgets' => 'array',
        'layout' => 'array',
        'is_default' => 'boolean',
        'is_shared' => 'boolean',
    ];

    // Dashboard types
    public const TYPE_MAIN = 'main';
    public const TYPE_SALES = 'sales';
    public const TYPE_INVENTORY = 'inventory';
    public const TYPE_FINANCE = 'finance';
    public const TYPE_HR = 'hr';
    public const TYPE_CRM = 'crm';
    public const TYPE_CUSTOM = 'custom';

    // Default layouts
    public const DEFAULT_LAYOUTS = [
        self::TYPE_MAIN => [
            'name' => 'Main Dashboard',
            'widgets' => [
                ['code' => 'total_sales_today', 'size' => '1x1', 'position' => ['x' => 0, 'y' => 0]],
                ['code' => 'total_sales_month', 'size' => '1x1', 'position' => ['x' => 1, 'y' => 0]],
                ['code' => 'outstanding_receivables', 'size' => '1x1', 'position' => ['x' => 2, 'y' => 0]],
                ['code' => 'low_stock_alerts', 'size' => '1x1', 'position' => ['x' => 3, 'y' => 0]],
                ['code' => 'sales_trend', 'size' => '2x2', 'position' => ['x' => 0, 'y' => 1]],
                ['code' => 'top_products', 'size' => '2x2', 'position' => ['x' => 2, 'y' => 1]],
                ['code' => 'recent_invoices', 'size' => '2x2', 'position' => ['x' => 0, 'y' => 3]],
                ['code' => 'activity_timeline', 'size' => '2x2', 'position' => ['x' => 2, 'y' => 3]],
            ],
            'layout' => [
                'columns' => 4,
                'row_height' => 150,
                'gap' => 16,
            ],
        ],
        self::TYPE_SALES => [
            'name' => 'Sales Dashboard',
            'widgets' => [
                ['code' => 'total_sales_today', 'size' => '1x1', 'position' => ['x' => 0, 'y' => 0]],
                ['code' => 'total_sales_month', 'size' => '1x1', 'position' => ['x' => 1, 'y' => 0]],
                ['code' => 'invoices_pending', 'size' => '1x1', 'position' => ['x' => 2, 'y' => 0]],
                ['code' => 'outstanding_receivables', 'size' => '1x1', 'position' => ['x' => 3, 'y' => 0]],
                ['code' => 'sales_trend', 'size' => '3x2', 'position' => ['x' => 0, 'y' => 1]],
                ['code' => 'sales_by_category', 'size' => '2x2', 'position' => ['x' => 3, 'y' => 1]],
                ['code' => 'top_customers', 'size' => '2x2', 'position' => ['x' => 0, 'y' => 3]],
                ['code' => 'top_products', 'size' => '2x2', 'position' => ['x' => 2, 'y' => 3]],
            ],
        ],
        self::TYPE_INVENTORY => [
            'name' => 'Inventory Dashboard',
            'widgets' => [
                ['code' => 'inventory_value', 'size' => '2x1', 'position' => ['x' => 0, 'y' => 0]],
                ['code' => 'low_stock_alerts', 'size' => '1x1', 'position' => ['x' => 2, 'y' => 0]],
                ['code' => 'expiring_products', 'size' => '2x2', 'position' => ['x' => 0, 'y' => 1]],
                ['code' => 'stock_movement', 'size' => '2x2', 'position' => ['x' => 2, 'y' => 1]],
            ],
        ],
        self::TYPE_FINANCE => [
            'name' => 'Finance Dashboard',
            'widgets' => [
                ['code' => 'cash_balance', 'size' => '1x1', 'position' => ['x' => 0, 'y' => 0]],
                ['code' => 'outstanding_receivables', 'size' => '1x1', 'position' => ['x' => 1, 'y' => 0]],
                ['code' => 'outstanding_payables', 'size' => '1x1', 'position' => ['x' => 2, 'y' => 0]],
                ['code' => 'profit_loss_summary', 'size' => '2x2', 'position' => ['x' => 0, 'y' => 1]],
                ['code' => 'revenue_vs_expense', 'size' => '3x2', 'position' => ['x' => 2, 'y' => 1]],
            ],
        ],
    ];

    // Relationships

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // Scopes

    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeForType($query, string $type)
    {
        return $query->where('type', $type);
    }

    public function scopeDefault($query)
    {
        return $query->where('is_default', true);
    }

    public function scopeShared($query)
    {
        return $query->where('is_shared', true);
    }

    // Helpers

    public static function getForUser(int $organizationId, int $userId, string $type = self::TYPE_MAIN): self
    {
        // First try user's own layout
        $layout = static::where('organization_id', $organizationId)
            ->where('user_id', $userId)
            ->where('type', $type)
            ->where('is_default', true)
            ->first();

        if ($layout) {
            return $layout;
        }

        // Then try shared organization layout
        $layout = static::where('organization_id', $organizationId)
            ->whereNull('user_id')
            ->where('type', $type)
            ->where('is_shared', true)
            ->where('is_default', true)
            ->first();

        if ($layout) {
            return $layout;
        }

        // Create default layout
        return static::createDefaultLayout($organizationId, $userId, $type);
    }

    public static function createDefaultLayout(int $organizationId, ?int $userId, string $type): self
    {
        $default = self::DEFAULT_LAYOUTS[$type] ?? self::DEFAULT_LAYOUTS[self::TYPE_MAIN];

        return static::create([
            'organization_id' => $organizationId,
            'user_id' => $userId,
            'name' => $default['name'],
            'type' => $type,
            'widgets' => $default['widgets'],
            'layout' => $default['layout'] ?? ['columns' => 4, 'row_height' => 150, 'gap' => 16],
            'is_default' => true,
            'is_shared' => $userId === null,
        ]);
    }

    public function addWidget(string $widgetCode, string $size, array $position, array $config = []): void
    {
        $widgets = $this->widgets ?? [];

        $widgets[] = [
            'code' => $widgetCode,
            'size' => $size,
            'position' => $position,
            'config' => $config,
        ];

        $this->widgets = $widgets;
        $this->save();
    }

    public function removeWidget(string $widgetCode): void
    {
        $widgets = collect($this->widgets ?? [])->filter(function ($widget) use ($widgetCode) {
            return $widget['code'] !== $widgetCode;
        })->values()->toArray();

        $this->widgets = $widgets;
        $this->save();
    }

    public function updateWidgetPosition(string $widgetCode, array $position): void
    {
        $widgets = collect($this->widgets ?? [])->map(function ($widget) use ($widgetCode, $position) {
            if ($widget['code'] === $widgetCode) {
                $widget['position'] = $position;
            }
            return $widget;
        })->toArray();

        $this->widgets = $widgets;
        $this->save();
    }

    public function updateWidgetConfig(string $widgetCode, array $config): void
    {
        $widgets = collect($this->widgets ?? [])->map(function ($widget) use ($widgetCode, $config) {
            if ($widget['code'] === $widgetCode) {
                $widget['config'] = array_merge($widget['config'] ?? [], $config);
            }
            return $widget;
        })->toArray();

        $this->widgets = $widgets;
        $this->save();
    }

    public static function getTypes(): array
    {
        return [
            self::TYPE_MAIN => 'Main Dashboard',
            self::TYPE_SALES => 'Sales Dashboard',
            self::TYPE_INVENTORY => 'Inventory Dashboard',
            self::TYPE_FINANCE => 'Finance Dashboard',
            self::TYPE_HR => 'HR Dashboard',
            self::TYPE_CRM => 'CRM Dashboard',
            self::TYPE_CUSTOM => 'Custom Dashboard',
        ];
    }
}
