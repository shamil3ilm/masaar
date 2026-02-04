<?php

declare(strict_types=1);

namespace App\Services\Core;

use App\Models\Core\Organization;
use App\Models\Core\OrganizationModule;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class ModuleService
{
    protected const CACHE_PREFIX = 'org_modules_';
    protected const CACHE_TTL = 3600; // 1 hour

    /**
     * Get all available modules from config.
     */
    public function getAllModules(): array
    {
        return config('modules.modules', []);
    }

    /**
     * Get module definition by code.
     */
    public function getModuleDefinition(string $moduleCode): ?array
    {
        return config("modules.modules.{$moduleCode}");
    }

    /**
     * Get enabled modules for an organization.
     */
    public function getEnabledModules(int $organizationId): Collection
    {
        return Cache::remember(
            self::CACHE_PREFIX . $organizationId,
            self::CACHE_TTL,
            fn () => OrganizationModule::where('organization_id', $organizationId)
                ->where('is_enabled', true)
                ->get()
                ->keyBy('module_code')
        );
    }

    /**
     * Get enabled module codes for an organization.
     */
    public function getEnabledModuleCodes(int $organizationId): array
    {
        return $this->getEnabledModules($organizationId)->keys()->toArray();
    }

    /**
     * Check if a module is enabled for an organization.
     */
    public function isModuleEnabled(int $organizationId, string $moduleCode): bool
    {
        // Core module is always enabled
        if ($moduleCode === 'core') {
            return true;
        }

        $enabledModules = $this->getEnabledModules($organizationId);

        return $enabledModules->has($moduleCode);
    }

    /**
     * Check if a feature within a module is enabled.
     */
    public function isFeatureEnabled(int $organizationId, string $moduleCode, string $feature): bool
    {
        if (!$this->isModuleEnabled($organizationId, $moduleCode)) {
            return false;
        }

        $module = $this->getEnabledModules($organizationId)->get($moduleCode);

        return $module ? $module->isFeatureEnabled($feature) : false;
    }

    /**
     * Enable a module for an organization.
     */
    public function enableModule(int $organizationId, string $moduleCode, ?int $userId = null): OrganizationModule
    {
        $definition = $this->getModuleDefinition($moduleCode);

        if (!$definition) {
            throw new \InvalidArgumentException("Module '{$moduleCode}' does not exist.");
        }

        // Check dependencies
        $this->validateDependencies($organizationId, $moduleCode);

        // Check subscription tier
        $this->validateSubscriptionTier($organizationId, $moduleCode);

        $module = OrganizationModule::updateOrCreate(
            [
                'organization_id' => $organizationId,
                'module_code' => $moduleCode,
            ],
            [
                'is_enabled' => true,
                'enabled_at' => now(),
                'disabled_at' => null,
                'enabled_by' => $userId,
            ]
        );

        $this->clearCache($organizationId);

        return $module;
    }

    /**
     * Disable a module for an organization.
     */
    public function disableModule(int $organizationId, string $moduleCode): void
    {
        $definition = $this->getModuleDefinition($moduleCode);

        if (!$definition) {
            throw new \InvalidArgumentException("Module '{$moduleCode}' does not exist.");
        }

        if ($definition['is_required'] ?? false) {
            throw new \InvalidArgumentException("Module '{$moduleCode}' is required and cannot be disabled.");
        }

        // Check if other modules depend on this one
        $dependentModules = $this->getModulesDependingOn($organizationId, $moduleCode);

        if ($dependentModules->isNotEmpty()) {
            $names = $dependentModules->map(fn ($m) => $m->getModuleName())->implode(', ');
            throw new \InvalidArgumentException("Cannot disable '{$moduleCode}'. The following modules depend on it: {$names}");
        }

        OrganizationModule::where('organization_id', $organizationId)
            ->where('module_code', $moduleCode)
            ->update([
                'is_enabled' => false,
                'disabled_at' => now(),
            ]);

        $this->clearCache($organizationId);
    }

    /**
     * Enable specific features within a module.
     */
    public function setEnabledFeatures(int $organizationId, string $moduleCode, array $features): void
    {
        $module = OrganizationModule::where('organization_id', $organizationId)
            ->where('module_code', $moduleCode)
            ->first();

        if (!$module) {
            throw new \InvalidArgumentException("Module '{$moduleCode}' is not configured for this organization.");
        }

        $module->update(['enabled_features' => $features]);

        $this->clearCache($organizationId);
    }

    /**
     * Initialize modules for a new organization based on subscription tier.
     */
    public function initializeModulesForOrganization(Organization $organization): void
    {
        $tier = $organization->subscription_tier ?? 'standard';
        $tierConfig = config("modules.tiers.{$tier}", []);
        $allowedModules = $tierConfig['modules'] ?? ['core'];

        DB::transaction(function () use ($organization, $allowedModules) {
            foreach ($allowedModules as $moduleCode) {
                OrganizationModule::updateOrCreate(
                    [
                        'organization_id' => $organization->id,
                        'module_code' => $moduleCode,
                    ],
                    [
                        'is_enabled' => true,
                        'enabled_at' => now(),
                    ]
                );
            }
        });

        $this->clearCache($organization->id);
    }

    /**
     * Update modules based on subscription tier change.
     */
    public function updateModulesForTierChange(int $organizationId, string $newTier): void
    {
        $tierConfig = config("modules.tiers.{$newTier}", []);
        $allowedModules = $tierConfig['modules'] ?? ['core'];

        // Disable modules not in new tier
        OrganizationModule::where('organization_id', $organizationId)
            ->whereNotIn('module_code', $allowedModules)
            ->update([
                'is_enabled' => false,
                'disabled_at' => now(),
            ]);

        // Enable modules in new tier
        foreach ($allowedModules as $moduleCode) {
            OrganizationModule::updateOrCreate(
                [
                    'organization_id' => $organizationId,
                    'module_code' => $moduleCode,
                ],
                [
                    'is_enabled' => true,
                    'enabled_at' => now(),
                ]
            );
        }

        $this->clearCache($organizationId);
    }

    /**
     * Get modules that an organization can enable based on tier.
     */
    public function getAvailableModulesForOrganization(int $organizationId): array
    {
        $organization = Organization::findOrFail($organizationId);
        $tier = $organization->subscription_tier ?? 'standard';
        $tierConfig = config("modules.tiers.{$tier}", []);
        $allowedModuleCodes = $tierConfig['modules'] ?? ['core'];

        $allModules = $this->getAllModules();
        $enabledModules = $this->getEnabledModules($organizationId);

        $result = [];

        foreach ($allModules as $code => $definition) {
            $isAllowed = in_array($code, $allowedModuleCodes);
            $isEnabled = $enabledModules->has($code);
            $enabledModule = $enabledModules->get($code);

            $result[$code] = [
                'code' => $code,
                'name' => $definition['name'],
                'description' => $definition['description'],
                'icon' => $definition['icon'],
                'color' => $definition['color'],
                'tier' => $definition['tier'],
                'is_required' => $definition['is_required'] ?? false,
                'is_allowed' => $isAllowed,
                'is_enabled' => $isEnabled,
                'dependencies' => $definition['dependencies'] ?? [],
                'features' => $definition['features'] ?? [],
                'reports' => $definition['reports'] ?? [],
                'enabled_features' => $enabledModule?->enabled_features,
                'enabled_at' => $enabledModule?->enabled_at?->toIso8601String(),
            ];
        }

        return $result;
    }

    /**
     * Get organization's module summary for dashboard.
     */
    public function getModuleSummary(int $organizationId): array
    {
        $allModules = $this->getAllModules();
        $enabledModules = $this->getEnabledModules($organizationId);

        return [
            'total_modules' => count($allModules),
            'enabled_modules' => $enabledModules->count(),
            'enabled_module_codes' => $enabledModules->keys()->toArray(),
            'modules' => $enabledModules->map(fn ($m) => [
                'code' => $m->module_code,
                'name' => $m->getModuleName(),
                'icon' => $allModules[$m->module_code]['icon'] ?? 'box',
                'color' => $allModules[$m->module_code]['color'] ?? '#6b7280',
            ])->values()->toArray(),
        ];
    }

    /**
     * Get user's accessible modules.
     */
    public function getUserModules(User $user): array
    {
        $enabledModules = $this->getEnabledModuleCodes($user->organization_id);

        // If user has specific module access restrictions, apply them
        if (!empty($user->module_access)) {
            return array_intersect($enabledModules, $user->module_access);
        }

        return $enabledModules;
    }

    /**
     * Set user-specific module access.
     */
    public function setUserModuleAccess(User $user, array $modules): void
    {
        $enabledModules = $this->getEnabledModuleCodes($user->organization_id);
        $validModules = array_intersect($modules, $enabledModules);

        // Always include core
        if (!in_array('core', $validModules)) {
            array_unshift($validModules, 'core');
        }

        $user->update(['module_access' => $validModules]);
    }

    /**
     * Clear user module access (give access to all org modules).
     */
    public function clearUserModuleAccess(User $user): void
    {
        $user->update(['module_access' => null]);
    }

    /**
     * Validate module dependencies are enabled.
     */
    protected function validateDependencies(int $organizationId, string $moduleCode): void
    {
        $definition = $this->getModuleDefinition($moduleCode);
        $dependencies = $definition['dependencies'] ?? [];

        foreach ($dependencies as $dependency) {
            if (!$this->isModuleEnabled($organizationId, $dependency)) {
                $depName = $this->getModuleDefinition($dependency)['name'] ?? $dependency;
                throw new \InvalidArgumentException(
                    "Cannot enable '{$moduleCode}'. Required module '{$depName}' is not enabled."
                );
            }
        }
    }

    /**
     * Validate subscription tier allows module.
     */
    protected function validateSubscriptionTier(int $organizationId, string $moduleCode): void
    {
        $organization = Organization::findOrFail($organizationId);
        $tier = $organization->subscription_tier ?? 'standard';
        $tierConfig = config("modules.tiers.{$tier}", []);
        $allowedModules = $tierConfig['modules'] ?? ['core'];

        if (!in_array($moduleCode, $allowedModules)) {
            $definition = $this->getModuleDefinition($moduleCode);
            $requiredTier = $definition['tier'] ?? 'enterprise';
            throw new \InvalidArgumentException(
                "Module '{$moduleCode}' requires {$requiredTier} tier or higher."
            );
        }
    }

    /**
     * Get modules that depend on a given module.
     */
    protected function getModulesDependingOn(int $organizationId, string $moduleCode): Collection
    {
        $enabledModules = $this->getEnabledModules($organizationId);
        $allModules = $this->getAllModules();

        return $enabledModules->filter(function ($module) use ($moduleCode, $allModules) {
            $definition = $allModules[$module->module_code] ?? [];
            $dependencies = $definition['dependencies'] ?? [];

            return in_array($moduleCode, $dependencies);
        });
    }

    /**
     * Clear module cache for organization.
     */
    public function clearCache(int $organizationId): void
    {
        Cache::forget(self::CACHE_PREFIX . $organizationId);
    }

    /**
     * Get subscription tiers.
     */
    public function getSubscriptionTiers(): array
    {
        return config('modules.tiers', []);
    }
}
