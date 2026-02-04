<?php

declare(strict_types=1);

namespace App\Models\System;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id',
        'group',
        'key',
        'value',
        'type',
    ];

    // Get typed value
    public function getTypedValueAttribute(): mixed
    {
        return match ($this->type) {
            'integer' => (int) $this->value,
            'boolean' => filter_var($this->value, FILTER_VALIDATE_BOOLEAN),
            'json', 'array' => json_decode($this->value, true),
            'float' => (float) $this->value,
            default => $this->value,
        };
    }

    // Static helper methods
    public static function get(string $group, string $key, mixed $default = null, ?int $organizationId = null): mixed
    {
        $organizationId = $organizationId ?? auth()->user()?->organization_id;

        $setting = static::where('organization_id', $organizationId)
            ->where('group', $group)
            ->where('key', $key)
            ->first();

        return $setting?->typed_value ?? $default;
    }

    public static function set(string $group, string $key, mixed $value, ?string $type = null, ?int $organizationId = null): void
    {
        $organizationId = $organizationId ?? auth()->user()?->organization_id;

        if ($type === null) {
            $type = match (true) {
                is_bool($value) => 'boolean',
                is_int($value) => 'integer',
                is_float($value) => 'float',
                is_array($value) => 'array',
                default => 'string',
            };
        }

        $stringValue = match ($type) {
            'boolean' => $value ? 'true' : 'false',
            'array', 'json' => json_encode($value),
            default => (string) $value,
        };

        static::updateOrCreate(
            [
                'organization_id' => $organizationId,
                'group' => $group,
                'key' => $key,
            ],
            [
                'value' => $stringValue,
                'type' => $type,
            ]
        );
    }

    public static function getGroup(string $group, ?int $organizationId = null): array
    {
        $organizationId = $organizationId ?? auth()->user()?->organization_id;

        return static::where('organization_id', $organizationId)
            ->where('group', $group)
            ->get()
            ->mapWithKeys(fn ($setting) => [$setting->key => $setting->typed_value])
            ->toArray();
    }

    public static function setGroup(string $group, array $settings, ?int $organizationId = null): void
    {
        foreach ($settings as $key => $value) {
            static::set($group, $key, $value, null, $organizationId);
        }
    }
}
