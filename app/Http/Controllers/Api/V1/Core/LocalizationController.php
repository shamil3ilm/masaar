<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Core;

use App\Http\Controllers\Controller;
use App\Models\Core\Language;
use App\Models\Core\OrganizationBranding;
use App\Models\Core\Translation;
use App\Services\Core\LocalizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class LocalizationController extends Controller
{
    public function __construct(
        protected LocalizationService $localizationService
    ) {}

    /**
     * Get all localization data for frontend.
     */
    public function index(Request $request): JsonResponse
    {
        $this->localizationService->setOrganization($request->user()->organization_id);
        $this->localizationService->setLanguage($request->user()->preferred_language ?? 'en');

        return response()->json([
            'data' => $this->localizationService->getFrontendData(),
        ]);
    }

    /**
     * Get available languages.
     */
    public function languages(): JsonResponse
    {
        $languages = Language::active()->ordered()->get();

        return response()->json([
            'data' => $languages,
            'default' => Language::getDefault(),
        ]);
    }

    /**
     * Get translations for a language.
     */
    public function translations(Request $request, string $languageCode): JsonResponse
    {
        $organizationId = $request->user()->organization_id;

        $translations = Translation::getAllForLanguage($languageCode, $organizationId);

        return response()->json([
            'data' => $translations,
            'language' => $languageCode,
            'direction' => $this->localizationService->getDirection($languageCode),
        ]);
    }

    /**
     * Get translations for a specific group.
     */
    public function translationGroup(Request $request, string $languageCode, string $group): JsonResponse
    {
        $organizationId = $request->user()->organization_id;

        $translations = Translation::getGroup($group, $languageCode, $organizationId);

        return response()->json([
            'data' => $translations,
            'group' => $group,
            'language' => $languageCode,
        ]);
    }

    /**
     * Update a translation.
     */
    public function updateTranslation(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'key' => 'required|string',
            'value' => 'required|string',
            'language_code' => 'required|string|max:10',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $translation = Translation::set(
            $request->get('key'),
            $request->get('value'),
            $request->get('language_code'),
            $request->user()->organization_id
        );

        return response()->json(['data' => $translation]);
    }

    /**
     * Bulk update translations.
     */
    public function updateTranslations(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'language_code' => 'required|string|max:10',
            'translations' => 'required|array',
            'translations.*.key' => 'required|string',
            'translations.*.value' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $languageCode = $request->get('language_code');
        $organizationId = $request->user()->organization_id;
        $count = 0;

        foreach ($request->get('translations') as $item) {
            Translation::set($item['key'], $item['value'], $languageCode, $organizationId);
            $count++;
        }

        Translation::clearCache();

        return response()->json([
            'message' => "Updated {$count} translations",
            'count' => $count,
        ]);
    }

    /**
     * Get organization branding.
     */
    public function getBranding(Request $request): JsonResponse
    {
        $branding = OrganizationBranding::getForOrganization($request->user()->organization_id);

        return response()->json([
            'data' => $branding,
            'css_variables' => $branding->getCssVariables(),
            'presets' => OrganizationBranding::COLOR_PRESETS,
            'font_options' => OrganizationBranding::FONT_OPTIONS,
            'arabic_font_options' => OrganizationBranding::ARABIC_FONT_OPTIONS,
        ]);
    }

    /**
     * Update organization branding.
     */
    public function updateBranding(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'primary_color' => 'nullable|string|max:20',
            'secondary_color' => 'nullable|string|max:20',
            'accent_color' => 'nullable|string|max:20',
            'danger_color' => 'nullable|string|max:20',
            'warning_color' => 'nullable|string|max:20',
            'success_color' => 'nullable|string|max:20',
            'info_color' => 'nullable|string|max:20',
            'text_color' => 'nullable|string|max:20',
            'background_color' => 'nullable|string|max:20',
            'sidebar_color' => 'nullable|string|max:20',
            'header_color' => 'nullable|string|max:20',
            'font_family' => 'nullable|string|max:100',
            'font_family_arabic' => 'nullable|string|max:100',
            'base_font_size' => 'nullable|integer|min:10|max:20',
            'theme' => 'nullable|string|in:light,dark,auto',
            'enable_dark_mode' => 'nullable|boolean',
            'custom_css' => 'nullable|string|max:10000',
            'email_header_color' => 'nullable|string|max:20',
            'email_footer_text' => 'nullable|string|max:500',
            'document_watermark' => 'nullable|string|max:100',
            'document_footer_text' => 'nullable|string|max:500',
            'preset' => 'nullable|string', // Apply a preset
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $branding = OrganizationBranding::getForOrganization($request->user()->organization_id);

        // Apply preset if specified
        if ($request->has('preset')) {
            $branding->applyPreset($request->get('preset'));
        }

        $branding->fill($request->except('preset'));
        $branding->save();

        return response()->json([
            'data' => $branding,
            'css_variables' => $branding->getCssVariables(),
            'message' => 'Branding updated successfully',
        ]);
    }

    /**
     * Upload logo.
     */
    public function uploadLogo(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'logo' => 'required|image|mimes:jpeg,png,gif,svg,webp|max:2048',
            'type' => 'required|string|in:logo,logo_dark,favicon,login_background',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $file = $request->file('logo');
        $type = $request->get('type');
        $organizationId = $request->user()->organization_id;

        $path = $file->store("organizations/{$organizationId}/branding", 'public');
        $url = Storage::disk('public')->url($path);

        $branding = OrganizationBranding::getForOrganization($organizationId);

        $fieldMap = [
            'logo' => 'logo_url',
            'logo_dark' => 'logo_dark_url',
            'favicon' => 'favicon_url',
            'login_background' => 'login_background_url',
        ];

        $field = $fieldMap[$type];

        // Delete old file if exists
        if ($branding->$field) {
            $oldPath = str_replace(Storage::disk('public')->url(''), '', $branding->$field);
            Storage::disk('public')->delete($oldPath);
        }

        $branding->$field = $url;
        $branding->save();

        return response()->json([
            'data' => ['url' => $url],
            'message' => 'Logo uploaded successfully',
        ]);
    }

    /**
     * Get available translation groups.
     */
    public function translationGroups(): JsonResponse
    {
        return response()->json([
            'data' => Translation::getGroups(),
        ]);
    }

    /**
     * Set user's preferred language.
     */
    public function setUserLanguage(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'language_code' => 'required|string|max:10|exists:languages,code',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user = $request->user();
        $user->preferred_language = $request->get('language_code');
        $user->save();

        return response()->json([
            'message' => 'Language preference updated',
            'language' => $user->preferred_language,
        ]);
    }
}
