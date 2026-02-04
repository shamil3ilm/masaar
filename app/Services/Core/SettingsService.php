<?php

declare(strict_types=1);

namespace App\Services\Core;

use App\Models\System\Setting;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

/**
 * Service for managing organization and system settings.
 * Settings are validated, cached, and versioned.
 */
class SettingsService
{
    private const CACHE_PREFIX = 'settings:';
    private const CACHE_TTL = 3600; // 1 hour

    /**
     * Setting definitions with types and defaults.
     */
    private array $definitions = [
        // Organization Settings
        'org.name' => ['type' => 'string', 'required' => true],
        'org.logo_url' => ['type' => 'string', 'nullable' => true],
        'org.primary_color' => ['type' => 'string', 'default' => '#3B82F6'],
        'org.date_format' => ['type' => 'string', 'default' => 'Y-m-d', 'options' => ['Y-m-d', 'd/m/Y', 'm/d/Y', 'd-m-Y']],
        'org.time_format' => ['type' => 'string', 'default' => 'H:i', 'options' => ['H:i', 'h:i A']],
        'org.first_day_of_week' => ['type' => 'integer', 'default' => 0, 'min' => 0, 'max' => 6],

        // Accounting Settings
        'accounting.fiscal_year_start_month' => ['type' => 'integer', 'default' => 1, 'min' => 1, 'max' => 12],
        'accounting.default_currency' => ['type' => 'string', 'default' => 'SAR'],
        'accounting.multi_currency_enabled' => ['type' => 'boolean', 'default' => true],
        'accounting.auto_post_journals' => ['type' => 'boolean', 'default' => false],
        'accounting.require_journal_approval' => ['type' => 'boolean', 'default' => false],

        // Invoice Settings
        'invoice.prefix' => ['type' => 'string', 'default' => 'INV-'],
        'invoice.starting_number' => ['type' => 'integer', 'default' => 1, 'min' => 1],
        'invoice.due_days' => ['type' => 'integer', 'default' => 30, 'min' => 0],
        'invoice.auto_send_email' => ['type' => 'boolean', 'default' => false],
        'invoice.show_tax_breakdown' => ['type' => 'boolean', 'default' => true],

        // Tax Settings
        'tax.default_rate' => ['type' => 'decimal', 'default' => 15.00, 'min' => 0, 'max' => 100],
        'tax.inclusive_pricing' => ['type' => 'boolean', 'default' => false],

        // Notification Settings
        'notifications.email_enabled' => ['type' => 'boolean', 'default' => true],
        'notifications.low_stock_threshold' => ['type' => 'integer', 'default' => 10, 'min' => 0],

        // Security Settings
        'security.password_expiry_days' => ['type' => 'integer', 'default' => 0, 'min' => 0], // 0 = never
        'security.session_timeout_minutes' => ['type' => 'integer', 'default' => 60, 'min' => 5],
        'security.require_2fa' => ['type' => 'boolean', 'default' => false],
    ];

    /**
     * Get a setting value.
     */
    public function get(string $key, int $organizationId, mixed $default = null): mixed
    {
        $cacheKey = $this->getCacheKey($key, $organizationId);

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($key, $organizationId, $default) {
            $setting = Setting::where('organization_id', $organizationId)
                ->where('key', $key)
                ->first();

            if (!$setting) {
                return $default ?? $this->getDefault($key);
            }

            return $this->castValue($setting->value, $this->getType($key));
        });
    }

    /**
     * Get multiple settings at once.
     */
    public function getMany(array $keys, int $organizationId): array
    {
        $result = [];

        foreach ($keys as $key) {
            $result[$key] = $this->get($key, $organizationId);
        }

        return $result;
    }

    /**
     * Get all settings for an organization.
     */
    public function getAll(int $organizationId): array
    {
        $settings = Setting::where('organization_id', $organizationId)
            ->pluck('value', 'key')
            ->toArray();

        // Merge with defaults
        $result = [];
        foreach ($this->definitions as $key => $definition) {
            if (isset($settings[$key])) {
                $result[$key] = $this->castValue($settings[$key], $definition['type']);
            } else {
                $result[$key] = $definition['default'] ?? null;
            }
        }

        return $result;
    }

    /**
     * Set a setting value.
     */
    public function set(string $key, mixed $value, int $organizationId): void
    {
        $this->validateSetting($key, $value);

        Setting::updateOrCreate(
            ['organization_id' => $organizationId, 'key' => $key],
            ['value' => $this->serializeValue($value)]
        );

        $this->clearCache($key, $organizationId);
    }

    /**
     * Set multiple settings at once.
     */
    public function setMany(array $settings, int $organizationId): void
    {
        foreach ($settings as $key => $value) {
            $this->set($key, $value, $organizationId);
        }
    }

    /**
     * Delete a setting (revert to default).
     */
    public function delete(string $key, int $organizationId): void
    {
        Setting::where('organization_id', $organizationId)
            ->where('key', $key)
            ->delete();

        $this->clearCache($key, $organizationId);
    }

    /**
     * Get setting definition.
     */
    public function getDefinition(string $key): ?array
    {
        return $this->definitions[$key] ?? null;
    }

    /**
     * Get all setting definitions.
     */
    public function getDefinitions(): array
    {
        return $this->definitions;
    }

    /**
     * Validate a setting value against its definition.
     */
    protected function validateSetting(string $key, mixed $value): void
    {
        $definition = $this->definitions[$key] ?? null;

        if (!$definition) {
            throw new InvalidArgumentException("Unknown setting: {$key}");
        }

        // Check required
        if (($definition['required'] ?? false) && $value === null) {
            throw new InvalidArgumentException("Setting '{$key}' is required");
        }

        // Check nullable
        if ($value === null && ($definition['nullable'] ?? false)) {
            return;
        }

        // Type validation
        switch ($definition['type']) {
            case 'integer':
                if (!is_int($value) && !is_numeric($value)) {
                    throw new InvalidArgumentException("Setting '{$key}' must be an integer");
                }
                if (isset($definition['min']) && $value < $definition['min']) {
                    throw new InvalidArgumentException("Setting '{$key}' must be at least {$definition['min']}");
                }
                if (isset($definition['max']) && $value > $definition['max']) {
                    throw new InvalidArgumentException("Setting '{$key}' must be at most {$definition['max']}");
                }
                break;

            case 'decimal':
                if (!is_numeric($value)) {
                    throw new InvalidArgumentException("Setting '{$key}' must be a number");
                }
                if (isset($definition['min']) && $value < $definition['min']) {
                    throw new InvalidArgumentException("Setting '{$key}' must be at least {$definition['min']}");
                }
                if (isset($definition['max']) && $value > $definition['max']) {
                    throw new InvalidArgumentException("Setting '{$key}' must be at most {$definition['max']}");
                }
                break;

            case 'boolean':
                if (!is_bool($value) && !in_array($value, [0, 1, '0', '1', 'true', 'false'], true)) {
                    throw new InvalidArgumentException("Setting '{$key}' must be a boolean");
                }
                break;

            case 'string':
                if (!is_string($value)) {
                    throw new InvalidArgumentException("Setting '{$key}' must be a string");
                }
                if (isset($definition['options']) && !in_array($value, $definition['options'], true)) {
                    $options = implode(', ', $definition['options']);
                    throw new InvalidArgumentException("Setting '{$key}' must be one of: {$options}");
                }
                break;
        }
    }

    /**
     * Cast a value to the appropriate type.
     */
    protected function castValue(mixed $value, string $type): mixed
    {
        return match ($type) {
            'integer' => (int) $value,
            'decimal' => (float) $value,
            'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'string' => (string) $value,
            default => $value,
        };
    }

    /**
     * Serialize a value for storage.
     */
    protected function serializeValue(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return (string) $value;
    }

    /**
     * Get the type for a setting.
     */
    protected function getType(string $key): string
    {
        return $this->definitions[$key]['type'] ?? 'string';
    }

    /**
     * Get the default value for a setting.
     */
    protected function getDefault(string $key): mixed
    {
        return $this->definitions[$key]['default'] ?? null;
    }

    /**
     * Get cache key.
     */
    protected function getCacheKey(string $key, int $organizationId): string
    {
        return self::CACHE_PREFIX . "{$organizationId}:{$key}";
    }

    /**
     * Clear cache for a setting.
     */
    protected function clearCache(string $key, int $organizationId): void
    {
        Cache::forget($this->getCacheKey($key, $organizationId));
    }

    /**
     * Clear all settings cache for an organization.
     */
    public function clearAllCache(int $organizationId): void
    {
        foreach (array_keys($this->definitions) as $key) {
            $this->clearCache($key, $organizationId);
        }
    }
}
