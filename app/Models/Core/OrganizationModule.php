<?php

declare(strict_types=1);

namespace App\Models\Core;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrganizationModule extends Model
{
    protected $fillable = [
        'organization_id',
        'module_code',
        'is_enabled',
        'enabled_features',
        'settings',
        'enabled_at',
        'disabled_at',
        'enabled_by',
    ];

    protected $casts = [
        'is_enabled' => 'boolean',
        'enabled_features' => 'array',
        'settings' => 'array',
        'enabled_at' => 'datetime',
        'disabled_at' => 'datetime',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function enabledByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'enabled_by');
    }

    /**
     * Get module configuration from config.
     */
    public function getModuleConfig(): ?array
    {
        return config("modules.modules.{$this->module_code}");
    }

    /**
     * Get module name.
     */
    public function getModuleName(): string
    {
        return $this->getModuleConfig()['name'] ?? ucfirst($this->module_code);
    }

    /**
     * Check if a specific feature is enabled.
     */
    public function isFeatureEnabled(string $feature): bool
    {
        if (!$this->is_enabled) {
            return false;
        }

        // If no specific features are set, all features are enabled
        if (empty($this->enabled_features)) {
            return true;
        }

        return in_array($feature, $this->enabled_features);
    }

    /**
     * Get module setting.
     */
    public function getSetting(string $key, mixed $default = null): mixed
    {
        return data_get($this->settings, $key, $default);
    }

    /**
     * Enable the module.
     */
    public function enable(?int $userId = null): void
    {
        $this->update([
            'is_enabled' => true,
            'enabled_at' => now(),
            'disabled_at' => null,
            'enabled_by' => $userId,
        ]);
    }

    /**
     * Disable the module.
     */
    public function disable(): void
    {
        $this->update([
            'is_enabled' => false,
            'disabled_at' => now(),
        ]);
    }

    /**
     * Scope to get enabled modules.
     */
    public function scopeEnabled($query)
    {
        return $query->where('is_enabled', true);
    }

    /**
     * Scope to get modules for an organization.
     */
    public function scopeForOrganization($query, int $organizationId)
    {
        return $query->where('organization_id', $organizationId);
    }
}
