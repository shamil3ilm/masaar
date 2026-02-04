<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Core;

use App\Http\Controllers\Controller;
use App\Models\Core\FeatureFlag;
use App\Models\Core\NumberSequence;
use App\Models\Core\UserPreference;
use App\Models\System\Setting;
use App\Services\Core\SettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

class SettingsController extends Controller
{
    public function __construct(
        private readonly SettingsService $settingsService
    ) {}

    // ==========================================
    // Organization Settings
    // ==========================================

    /**
     * Get all organization settings.
     */
    public function index(Request $request): JsonResponse
    {
        $organizationId = auth()->user()->organization_id;

        $settings = $this->settingsService->getAll($organizationId);

        return response()->json([
            'success' => true,
            'data' => $settings,
        ]);
    }

    /**
     * Get settings by group.
     */
    public function getGroup(Request $request, string $group): JsonResponse
    {
        $organizationId = auth()->user()->organization_id;

        $settings = Setting::getGroup($group, $organizationId);

        return response()->json([
            'success' => true,
            'data' => [
                'group' => $group,
                'settings' => $settings,
            ],
        ]);
    }

    /**
     * Get a single setting value.
     */
    public function show(Request $request, string $key): JsonResponse
    {
        $organizationId = auth()->user()->organization_id;

        $value = $this->settingsService->get($key, $organizationId);
        $definition = $this->settingsService->getDefinition($key);

        return response()->json([
            'success' => true,
            'data' => [
                'key' => $key,
                'value' => $value,
                'definition' => $definition,
            ],
        ]);
    }

    /**
     * Update a single setting.
     */
    public function update(Request $request, string $key): JsonResponse
    {
        $request->validate([
            'value' => 'present',
        ]);

        $organizationId = auth()->user()->organization_id;

        try {
            $this->settingsService->set($key, $request->input('value'), $organizationId);

            return response()->json([
                'success' => true,
                'message' => 'Setting updated successfully',
                'data' => [
                    'key' => $key,
                    'value' => $this->settingsService->get($key, $organizationId),
                ],
            ]);
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['value' => $e->getMessage()]);
        }
    }

    /**
     * Update multiple settings at once.
     */
    public function updateMany(Request $request): JsonResponse
    {
        $request->validate([
            'settings' => 'required|array',
        ]);

        $organizationId = auth()->user()->organization_id;
        $errors = [];

        foreach ($request->input('settings') as $key => $value) {
            try {
                $this->settingsService->set($key, $value, $organizationId);
            } catch (\InvalidArgumentException $e) {
                $errors[$key] = $e->getMessage();
            }
        }

        if (!empty($errors)) {
            return response()->json([
                'success' => false,
                'message' => 'Some settings could not be updated',
                'errors' => $errors,
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Settings updated successfully',
        ]);
    }

    /**
     * Update settings for a group.
     */
    public function updateGroup(Request $request, string $group): JsonResponse
    {
        $request->validate([
            'settings' => 'required|array',
        ]);

        $organizationId = auth()->user()->organization_id;

        Setting::setGroup($group, $request->input('settings'), $organizationId);

        return response()->json([
            'success' => true,
            'message' => 'Group settings updated successfully',
            'data' => [
                'group' => $group,
                'settings' => Setting::getGroup($group, $organizationId),
            ],
        ]);
    }

    /**
     * Reset a setting to its default value.
     */
    public function reset(Request $request, string $key): JsonResponse
    {
        $organizationId = auth()->user()->organization_id;

        $this->settingsService->delete($key, $organizationId);

        return response()->json([
            'success' => true,
            'message' => 'Setting reset to default',
            'data' => [
                'key' => $key,
                'value' => $this->settingsService->get($key, $organizationId),
            ],
        ]);
    }

    /**
     * Get available setting definitions.
     */
    public function getDefinitions(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->settingsService->getDefinitions(),
        ]);
    }

    // ==========================================
    // User Preferences
    // ==========================================

    /**
     * Get all user preferences.
     */
    public function getUserPreferences(): JsonResponse
    {
        $userId = auth()->id();

        return response()->json([
            'success' => true,
            'data' => UserPreference::getAllForUser($userId),
        ]);
    }

    /**
     * Get a single user preference.
     */
    public function getUserPreference(string $key): JsonResponse
    {
        $userId = auth()->id();
        $value = UserPreference::getValue($userId, $key);

        return response()->json([
            'success' => true,
            'data' => [
                'key' => $key,
                'value' => $value,
            ],
        ]);
    }

    /**
     * Set a user preference.
     */
    public function setUserPreference(Request $request, string $key): JsonResponse
    {
        $request->validate([
            'value' => 'present',
        ]);

        $userId = auth()->id();
        UserPreference::setValue($userId, $key, $request->input('value'));

        return response()->json([
            'success' => true,
            'message' => 'Preference saved',
            'data' => [
                'key' => $key,
                'value' => $request->input('value'),
            ],
        ]);
    }

    /**
     * Update multiple user preferences.
     */
    public function setUserPreferences(Request $request): JsonResponse
    {
        $request->validate([
            'preferences' => 'required|array',
        ]);

        $userId = auth()->id();

        foreach ($request->input('preferences') as $key => $value) {
            UserPreference::setValue($userId, $key, $value);
        }

        return response()->json([
            'success' => true,
            'message' => 'Preferences saved',
            'data' => UserPreference::getAllForUser($userId),
        ]);
    }

    /**
     * Delete a user preference.
     */
    public function deleteUserPreference(string $key): JsonResponse
    {
        $userId = auth()->id();
        UserPreference::deleteValue($userId, $key);

        return response()->json([
            'success' => true,
            'message' => 'Preference deleted',
        ]);
    }

    // ==========================================
    // Feature Flags
    // ==========================================

    /**
     * Get all feature flags for the organization.
     */
    public function getFeatures(): JsonResponse
    {
        $organizationId = auth()->user()->organization_id;

        return response()->json([
            'success' => true,
            'data' => FeatureFlag::getAllForOrganization($organizationId),
        ]);
    }

    /**
     * Check if a feature is enabled.
     */
    public function checkFeature(string $feature): JsonResponse
    {
        $organizationId = auth()->user()->organization_id;

        return response()->json([
            'success' => true,
            'data' => [
                'feature' => $feature,
                'enabled' => FeatureFlag::isEnabled($organizationId, $feature),
                'config' => FeatureFlag::getConfig($organizationId, $feature),
            ],
        ]);
    }

    /**
     * Enable a feature.
     */
    public function enableFeature(Request $request, string $feature): JsonResponse
    {
        $organizationId = auth()->user()->organization_id;
        $config = $request->input('config');

        FeatureFlag::enableFeature($organizationId, $feature, $config);

        return response()->json([
            'success' => true,
            'message' => 'Feature enabled',
            'data' => [
                'feature' => $feature,
                'enabled' => true,
            ],
        ]);
    }

    /**
     * Disable a feature.
     */
    public function disableFeature(string $feature): JsonResponse
    {
        $organizationId = auth()->user()->organization_id;

        FeatureFlag::disableFeature($organizationId, $feature);

        return response()->json([
            'success' => true,
            'message' => 'Feature disabled',
            'data' => [
                'feature' => $feature,
                'enabled' => false,
            ],
        ]);
    }

    /**
     * Get available features list.
     */
    public function getAvailableFeatures(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => FeatureFlag::getAvailableFeatures(),
        ]);
    }

    // ==========================================
    // Number Sequences
    // ==========================================

    /**
     * Get all number sequences.
     */
    public function getNumberSequences(): JsonResponse
    {
        $organizationId = auth()->user()->organization_id;

        $sequences = NumberSequence::where('organization_id', $organizationId)
            ->orderBy('type')
            ->get()
            ->map(fn($seq) => [
                'id' => $seq->id,
                'type' => $seq->type,
                'branch_id' => $seq->branch_id,
                'prefix' => $seq->prefix,
                'suffix' => $seq->suffix,
                'current_number' => $seq->current_number,
                'padding' => $seq->padding,
                'include_year' => $seq->include_year,
                'include_month' => $seq->include_month,
                'reset_yearly' => $seq->reset_yearly,
                'reset_monthly' => $seq->reset_monthly,
                'next_number' => $seq->getFormattedNumber(),
            ]);

        return response()->json([
            'success' => true,
            'data' => $sequences,
        ]);
    }

    /**
     * Get a specific number sequence.
     */
    public function getNumberSequence(string $type, Request $request): JsonResponse
    {
        $organizationId = auth()->user()->organization_id;
        $branchId = $request->input('branch_id');

        $sequence = NumberSequence::where('organization_id', $organizationId)
            ->where('type', $type)
            ->where('branch_id', $branchId)
            ->first();

        if (!$sequence) {
            // Return default configuration
            $default = NumberSequence::DEFAULT_CONFIGS[$type] ?? ['prefix' => strtoupper($type) . '-', 'padding' => 5];
            return response()->json([
                'success' => true,
                'data' => [
                    'type' => $type,
                    'prefix' => $default['prefix'] ?? null,
                    'suffix' => $default['suffix'] ?? null,
                    'current_number' => 0,
                    'padding' => $default['padding'] ?? 5,
                    'include_year' => $default['include_year'] ?? true,
                    'include_month' => $default['include_month'] ?? false,
                    'reset_yearly' => $default['reset_yearly'] ?? true,
                    'reset_monthly' => $default['reset_monthly'] ?? false,
                    'is_default' => true,
                ],
            ]);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $sequence->id,
                'type' => $sequence->type,
                'branch_id' => $sequence->branch_id,
                'prefix' => $sequence->prefix,
                'suffix' => $sequence->suffix,
                'current_number' => $sequence->current_number,
                'padding' => $sequence->padding,
                'include_year' => $sequence->include_year,
                'include_month' => $sequence->include_month,
                'reset_yearly' => $sequence->reset_yearly,
                'reset_monthly' => $sequence->reset_monthly,
                'next_number' => NumberSequence::peekNext($organizationId, $type, $branchId),
                'is_default' => false,
            ],
        ]);
    }

    /**
     * Update a number sequence configuration.
     */
    public function updateNumberSequence(Request $request, string $type): JsonResponse
    {
        $request->validate([
            'branch_id' => 'nullable|exists:branches,id',
            'prefix' => 'nullable|string|max:20',
            'suffix' => 'nullable|string|max:20',
            'padding' => 'integer|min:1|max:10',
            'include_year' => 'boolean',
            'include_month' => 'boolean',
            'reset_yearly' => 'boolean',
            'reset_monthly' => 'boolean',
            'current_number' => 'nullable|integer|min:0',
        ]);

        $organizationId = auth()->user()->organization_id;
        $branchId = $request->input('branch_id');

        $sequence = NumberSequence::updateOrCreate(
            [
                'organization_id' => $organizationId,
                'type' => $type,
                'branch_id' => $branchId,
            ],
            array_filter([
                'prefix' => $request->input('prefix'),
                'suffix' => $request->input('suffix'),
                'padding' => $request->input('padding', 5),
                'include_year' => $request->input('include_year', true),
                'include_month' => $request->input('include_month', false),
                'reset_yearly' => $request->input('reset_yearly', true),
                'reset_monthly' => $request->input('reset_monthly', false),
                'current_number' => $request->input('current_number'),
                'last_reset_year' => now()->year,
                'last_reset_month' => now()->month,
            ], fn($v) => $v !== null)
        );

        return response()->json([
            'success' => true,
            'message' => 'Number sequence updated',
            'data' => [
                'id' => $sequence->id,
                'type' => $sequence->type,
                'next_number' => NumberSequence::peekNext($organizationId, $type, $branchId),
            ],
        ]);
    }

    /**
     * Preview next number in sequence.
     */
    public function previewNextNumber(string $type, Request $request): JsonResponse
    {
        $organizationId = auth()->user()->organization_id;
        $branchId = $request->input('branch_id');

        return response()->json([
            'success' => true,
            'data' => [
                'type' => $type,
                'next_number' => NumberSequence::peekNext($organizationId, $type, $branchId),
            ],
        ]);
    }

    /**
     * Get available sequence types.
     */
    public function getSequenceTypes(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => NumberSequence::DEFAULT_CONFIGS,
        ]);
    }

    // ==========================================
    // Cache Management
    // ==========================================

    /**
     * Clear all settings cache for the organization.
     */
    public function clearCache(): JsonResponse
    {
        $organizationId = auth()->user()->organization_id;

        $this->settingsService->clearAllCache($organizationId);

        // Clear feature flags cache
        $features = FeatureFlag::where('organization_id', $organizationId)
            ->pluck('feature');
        foreach ($features as $feature) {
            Cache::forget("feature_flag:{$organizationId}:{$feature}");
        }

        return response()->json([
            'success' => true,
            'message' => 'Settings cache cleared',
        ]);
    }
}
