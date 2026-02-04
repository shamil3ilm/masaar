<?php

declare(strict_types=1);

namespace App\Models\Core;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class Language extends Model
{
    protected $fillable = [
        'code',
        'name',
        'native_name',
        'direction',
        'locale',
        'flag_icon',
        'is_active',
        'is_default',
        'sort_order',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_default' => 'boolean',
    ];

    // Pre-defined languages
    public const LANGUAGES = [
        'en' => [
            'name' => 'English',
            'native_name' => 'English',
            'direction' => 'ltr',
            'locale' => 'en_US',
            'flag_icon' => '🇺🇸',
        ],
        'ar' => [
            'name' => 'Arabic',
            'native_name' => 'العربية',
            'direction' => 'rtl',
            'locale' => 'ar_SA',
            'flag_icon' => '🇸🇦',
        ],
        'ar_ae' => [
            'name' => 'Arabic (UAE)',
            'native_name' => 'العربية (الإمارات)',
            'direction' => 'rtl',
            'locale' => 'ar_AE',
            'flag_icon' => '🇦🇪',
        ],
        'hi' => [
            'name' => 'Hindi',
            'native_name' => 'हिन्दी',
            'direction' => 'ltr',
            'locale' => 'hi_IN',
            'flag_icon' => '🇮🇳',
        ],
        'ur' => [
            'name' => 'Urdu',
            'native_name' => 'اردو',
            'direction' => 'rtl',
            'locale' => 'ur_PK',
            'flag_icon' => '🇵🇰',
        ],
        'ta' => [
            'name' => 'Tamil',
            'native_name' => 'தமிழ்',
            'direction' => 'ltr',
            'locale' => 'ta_IN',
            'flag_icon' => '🇮🇳',
        ],
        'ml' => [
            'name' => 'Malayalam',
            'native_name' => 'മലയാളം',
            'direction' => 'ltr',
            'locale' => 'ml_IN',
            'flag_icon' => '🇮🇳',
        ],
        'bn' => [
            'name' => 'Bengali',
            'native_name' => 'বাংলা',
            'direction' => 'ltr',
            'locale' => 'bn_IN',
            'flag_icon' => '🇮🇳',
        ],
        'fr' => [
            'name' => 'French',
            'native_name' => 'Français',
            'direction' => 'ltr',
            'locale' => 'fr_FR',
            'flag_icon' => '🇫🇷',
        ],
    ];

    // Scopes

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }

    // Helpers

    public function isRtl(): bool
    {
        return $this->direction === 'rtl';
    }

    public static function getDefault(): ?self
    {
        return Cache::remember('language.default', 3600, function () {
            return static::where('is_default', true)->first()
                ?? static::where('code', 'en')->first();
        });
    }

    public static function getByCode(string $code): ?self
    {
        return Cache::remember("language.{$code}", 3600, function () use ($code) {
            return static::where('code', $code)->first();
        });
    }

    public static function getActiveLanguages(): array
    {
        return Cache::remember('languages.active', 3600, function () {
            return static::active()->ordered()->get()->toArray();
        });
    }

    public static function clearCache(): void
    {
        Cache::forget('language.default');
        Cache::forget('languages.active');

        foreach (array_keys(self::LANGUAGES) as $code) {
            Cache::forget("language.{$code}");
        }
    }

    protected static function booted(): void
    {
        static::saved(fn() => self::clearCache());
        static::deleted(fn() => self::clearCache());
    }
}
