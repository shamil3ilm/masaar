<?php

declare(strict_types=1);

namespace App\Models\Core;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class Translation extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id',
        'language_code',
        'group',
        'key',
        'value',
    ];

    // Translation groups
    public const GROUP_LABELS = 'labels';
    public const GROUP_MESSAGES = 'messages';
    public const GROUP_VALIDATION = 'validation';
    public const GROUP_INVOICE = 'invoice';
    public const GROUP_QUOTATION = 'quotation';
    public const GROUP_RECEIPT = 'receipt';
    public const GROUP_REPORT = 'report';
    public const GROUP_EMAIL = 'email';
    public const GROUP_DASHBOARD = 'dashboard';
    public const GROUP_MENU = 'menu';
    public const GROUP_BUTTON = 'button';
    public const GROUP_STATUS = 'status';
    public const GROUP_ERROR = 'error';
    public const GROUP_CUSTOM = 'custom';

    // Scopes

    public function scopeForLanguage($query, string $languageCode)
    {
        return $query->where('language_code', $languageCode);
    }

    public function scopeForGroup($query, string $group)
    {
        return $query->where('group', $group);
    }

    public function scopeGlobal($query)
    {
        return $query->whereNull('organization_id');
    }

    // Helpers

    public static function get(
        string $key,
        string $languageCode,
        ?int $organizationId = null,
        ?string $default = null
    ): ?string {
        $parts = explode('.', $key, 2);
        $group = count($parts) === 2 ? $parts[0] : 'labels';
        $actualKey = count($parts) === 2 ? $parts[1] : $key;

        $cacheKey = "trans.{$languageCode}.{$organizationId}.{$group}.{$actualKey}";

        return Cache::remember($cacheKey, 3600, function () use ($languageCode, $organizationId, $group, $actualKey, $default) {
            // First try organization-specific translation
            if ($organizationId) {
                $translation = static::where('organization_id', $organizationId)
                    ->where('language_code', $languageCode)
                    ->where('group', $group)
                    ->where('key', $actualKey)
                    ->first();

                if ($translation) {
                    return $translation->value;
                }
            }

            // Fall back to global translation
            $translation = static::whereNull('organization_id')
                ->where('language_code', $languageCode)
                ->where('group', $group)
                ->where('key', $actualKey)
                ->first();

            if ($translation) {
                return $translation->value;
            }

            // Fall back to English
            if ($languageCode !== 'en') {
                $translation = static::whereNull('organization_id')
                    ->where('language_code', 'en')
                    ->where('group', $group)
                    ->where('key', $actualKey)
                    ->first();

                if ($translation) {
                    return $translation->value;
                }
            }

            return $default;
        });
    }

    public static function getGroup(
        string $group,
        string $languageCode,
        ?int $organizationId = null
    ): array {
        $cacheKey = "trans.group.{$languageCode}.{$organizationId}.{$group}";

        return Cache::remember($cacheKey, 3600, function () use ($group, $languageCode, $organizationId) {
            $translations = [];

            // Get global translations first
            $global = static::whereNull('organization_id')
                ->where('language_code', $languageCode)
                ->where('group', $group)
                ->pluck('value', 'key')
                ->toArray();

            $translations = array_merge($translations, $global);

            // Override with organization-specific
            if ($organizationId) {
                $orgSpecific = static::where('organization_id', $organizationId)
                    ->where('language_code', $languageCode)
                    ->where('group', $group)
                    ->pluck('value', 'key')
                    ->toArray();

                $translations = array_merge($translations, $orgSpecific);
            }

            return $translations;
        });
    }

    public static function getAllForLanguage(
        string $languageCode,
        ?int $organizationId = null
    ): array {
        $groups = [
            self::GROUP_LABELS,
            self::GROUP_MESSAGES,
            self::GROUP_VALIDATION,
            self::GROUP_INVOICE,
            self::GROUP_QUOTATION,
            self::GROUP_RECEIPT,
            self::GROUP_REPORT,
            self::GROUP_EMAIL,
            self::GROUP_DASHBOARD,
            self::GROUP_MENU,
            self::GROUP_BUTTON,
            self::GROUP_STATUS,
            self::GROUP_ERROR,
            self::GROUP_CUSTOM,
        ];

        $all = [];
        foreach ($groups as $group) {
            $all[$group] = self::getGroup($group, $languageCode, $organizationId);
        }

        return $all;
    }

    public static function set(
        string $key,
        string $value,
        string $languageCode,
        ?int $organizationId = null
    ): self {
        $parts = explode('.', $key, 2);
        $group = count($parts) === 2 ? $parts[0] : 'labels';
        $actualKey = count($parts) === 2 ? $parts[1] : $key;

        $translation = static::updateOrCreate(
            [
                'organization_id' => $organizationId,
                'language_code' => $languageCode,
                'group' => $group,
                'key' => $actualKey,
            ],
            ['value' => $value]
        );

        // Clear cache
        self::clearCache($languageCode, $organizationId, $group);

        return $translation;
    }

    public static function clearCache(?string $languageCode = null, ?int $organizationId = null, ?string $group = null): void
    {
        // For simplicity, clear all translation cache
        // In production, use cache tags for more granular control
        Cache::flush();
    }

    public static function getGroups(): array
    {
        return [
            self::GROUP_LABELS => 'Labels',
            self::GROUP_MESSAGES => 'Messages',
            self::GROUP_VALIDATION => 'Validation',
            self::GROUP_INVOICE => 'Invoice',
            self::GROUP_QUOTATION => 'Quotation',
            self::GROUP_RECEIPT => 'Receipt',
            self::GROUP_REPORT => 'Report',
            self::GROUP_EMAIL => 'Email',
            self::GROUP_DASHBOARD => 'Dashboard',
            self::GROUP_MENU => 'Menu',
            self::GROUP_BUTTON => 'Button',
            self::GROUP_STATUS => 'Status',
            self::GROUP_ERROR => 'Error',
            self::GROUP_CUSTOM => 'Custom',
        ];
    }
}
