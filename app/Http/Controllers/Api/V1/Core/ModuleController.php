<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Core;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Core\ModuleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ModuleController extends Controller
{
    public function __construct(
        protected ModuleService $moduleService
    ) {}

    /**
     * Get all available modules and their status for the organization.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $modules = $this->moduleService->getAvailableModulesForOrganization($user->organization_id);

        return response()->json([
            'data' => $modules,
            'subscription_tier' => $user->organization->subscription_tier ?? 'standard',
        ]);
    }

    /**
     * Get enabled modules for the current user.
     */
    public function userModules(Request $request): JsonResponse
    {
        $user = $request->user();

        $enabledModules = $this->moduleService->getUserModules($user);
        $allModules = $this->moduleService->getAllModules();

        $modules = array_filter($allModules, fn ($code) => in_array($code, $enabledModules), ARRAY_FILTER_USE_KEY);

        return response()->json([
            'data' => array_map(fn ($m, $code) => [
                'code' => $code,
                'name' => $m['name'],
                'icon' => $m['icon'],
                'color' => $m['color'],
            ], $modules, array_keys($modules)),
        ]);
    }

    /**
     * Get module summary for navigation/dashboard.
     */
    public function summary(Request $request): JsonResponse
    {
        $user = $request->user();

        $summary = $this->moduleService->getModuleSummary($user->organization_id);

        return response()->json(['data' => $summary]);
    }

    /**
     * Enable a module for the organization.
     */
    public function enable(Request $request, string $moduleCode): JsonResponse
    {
        $user = $request->user();

        // Check permission
        if (!$user->hasPermission('core.settings.edit')) {
            return response()->json(['error' => 'Permission denied'], 403);
        }

        try {
            $module = $this->moduleService->enableModule(
                $user->organization_id,
                $moduleCode,
                $user->id
            );

            return response()->json([
                'data' => $module,
                'message' => "Module '{$moduleCode}' has been enabled.",
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }

    /**
     * Disable a module for the organization.
     */
    public function disable(Request $request, string $moduleCode): JsonResponse
    {
        $user = $request->user();

        // Check permission
        if (!$user->hasPermission('core.settings.edit')) {
            return response()->json(['error' => 'Permission denied'], 403);
        }

        try {
            $this->moduleService->disableModule($user->organization_id, $moduleCode);

            return response()->json([
                'message' => "Module '{$moduleCode}' has been disabled.",
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }

    /**
     * Update enabled features for a module.
     */
    public function updateFeatures(Request $request, string $moduleCode): JsonResponse
    {
        $user = $request->user();

        // Check permission
        if (!$user->hasPermission('core.settings.edit')) {
            return response()->json(['error' => 'Permission denied'], 403);
        }

        $validator = Validator::make($request->all(), [
            'features' => 'required|array',
            'features.*' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        try {
            $this->moduleService->setEnabledFeatures(
                $user->organization_id,
                $moduleCode,
                $request->get('features')
            );

            return response()->json([
                'message' => 'Module features updated successfully.',
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }

    /**
     * Get subscription tiers and their included modules.
     */
    public function tiers(Request $request): JsonResponse
    {
        $tiers = $this->moduleService->getSubscriptionTiers();
        $allModules = $this->moduleService->getAllModules();

        $result = [];

        foreach ($tiers as $code => $tier) {
            $result[$code] = [
                'code' => $code,
                'name' => $tier['name'],
                'max_users' => $tier['max_users'],
                'max_branches' => $tier['max_branches'],
                'modules' => array_map(fn ($m) => [
                    'code' => $m,
                    'name' => $allModules[$m]['name'] ?? $m,
                    'icon' => $allModules[$m]['icon'] ?? 'box',
                ], $tier['modules']),
            ];
        }

        return response()->json(['data' => $result]);
    }

    /**
     * Check if a specific module is enabled.
     */
    public function check(Request $request, string $moduleCode): JsonResponse
    {
        $user = $request->user();

        $isEnabled = $this->moduleService->isModuleEnabled($user->organization_id, $moduleCode);

        return response()->json([
            'module' => $moduleCode,
            'is_enabled' => $isEnabled,
        ]);
    }

    /**
     * Check if a specific feature is enabled.
     */
    public function checkFeature(Request $request, string $moduleCode, string $feature): JsonResponse
    {
        $user = $request->user();

        $isEnabled = $this->moduleService->isFeatureEnabled($user->organization_id, $moduleCode, $feature);

        return response()->json([
            'module' => $moduleCode,
            'feature' => $feature,
            'is_enabled' => $isEnabled,
        ]);
    }

    /**
     * Set user-specific module access.
     */
    public function setUserAccess(Request $request, int $userId): JsonResponse
    {
        $currentUser = $request->user();

        // Check permission
        if (!$currentUser->hasPermission('core.users.edit')) {
            return response()->json(['error' => 'Permission denied'], 403);
        }

        $validator = Validator::make($request->all(), [
            'modules' => 'required|array',
            'modules.*' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $targetUser = User::where('organization_id', $currentUser->organization_id)
            ->findOrFail($userId);

        $this->moduleService->setUserModuleAccess($targetUser, $request->get('modules'));

        return response()->json([
            'message' => 'User module access updated successfully.',
            'data' => [
                'user_id' => $userId,
                'modules' => $targetUser->fresh()->module_access,
            ],
        ]);
    }

    /**
     * Clear user-specific module access (give access to all org modules).
     */
    public function clearUserAccess(Request $request, int $userId): JsonResponse
    {
        $currentUser = $request->user();

        // Check permission
        if (!$currentUser->hasPermission('core.users.edit')) {
            return response()->json(['error' => 'Permission denied'], 403);
        }

        $targetUser = User::where('organization_id', $currentUser->organization_id)
            ->findOrFail($userId);

        $this->moduleService->clearUserModuleAccess($targetUser);

        return response()->json([
            'message' => 'User now has access to all organization modules.',
        ]);
    }

    /**
     * Get user's module access.
     */
    public function getUserAccess(Request $request, int $userId): JsonResponse
    {
        $currentUser = $request->user();

        // Users can view their own access, admins can view others
        if ($userId !== $currentUser->id && !$currentUser->hasPermission('core.users.view')) {
            return response()->json(['error' => 'Permission denied'], 403);
        }

        $targetUser = User::where('organization_id', $currentUser->organization_id)
            ->findOrFail($userId);

        $accessibleModules = $this->moduleService->getUserModules($targetUser);

        return response()->json([
            'data' => [
                'user_id' => $userId,
                'has_restrictions' => !empty($targetUser->module_access),
                'modules' => $accessibleModules,
            ],
        ]);
    }
}
