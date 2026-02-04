<?php

declare(strict_types=1);

namespace App\Models\Core;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserPreference extends Model
{
    protected $fillable = [
        'user_id',
        'key',
        'value',
    ];

    // Relationships

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // Helpers

    public function getDecodedValue(): mixed
    {
        return json_decode($this->value, true);
    }

    public function setEncodedValue(mixed $value): void
    {
        $this->value = json_encode($value);
    }

    // Static helpers for quick access

    public static function getValue(int $userId, string $key, mixed $default = null): mixed
    {
        $pref = static::where('user_id', $userId)
            ->where('key', $key)
            ->first();

        return $pref ? $pref->getDecodedValue() : $default;
    }

    public static function setValue(int $userId, string $key, mixed $value): static
    {
        return static::updateOrCreate(
            ['user_id' => $userId, 'key' => $key],
            ['value' => json_encode($value)]
        );
    }

    public static function deleteValue(int $userId, string $key): bool
    {
        return static::where('user_id', $userId)
            ->where('key', $key)
            ->delete() > 0;
    }

    public static function getAllForUser(int $userId): array
    {
        return static::where('user_id', $userId)
            ->get()
            ->mapWithKeys(fn($p) => [$p->key => $p->getDecodedValue()])
            ->toArray();
    }
}
