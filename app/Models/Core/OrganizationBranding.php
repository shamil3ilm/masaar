<?php

declare(strict_types=1);

namespace App\Models\Core;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;

class OrganizationBranding extends Model
{
    protected $table = 'organization_branding';

    protected $fillable = [
        'organization_id',
        // Logos
        'logo_url',
        'logo_dark_url',
        'favicon_url',
        'login_background_url',
        // Colors
        'primary_color',
        'secondary_color',
        'accent_color',
        'danger_color',
        'warning_color',
        'success_color',
        'info_color',
        'text_color',
        'background_color',
        'sidebar_color',
        'header_color',
        // Typography
        'font_family',
        'font_family_arabic',
        'base_font_size',
        // Theme
        'theme',
        'enable_dark_mode',
        // Custom CSS
        'custom_css',
        // Email
        'email_header_color',
        'email_footer_text',
        // Document
        'document_watermark',
        'document_footer_text',
    ];

    protected $casts = [
        'base_font_size' => 'integer',
        'enable_dark_mode' => 'boolean',
    ];

    // Default colors
    public const DEFAULT_COLORS = [
        'primary_color' => '#3498db',
        'secondary_color' => '#2ecc71',
        'accent_color' => '#9b59b6',
        'danger_color' => '#e74c3c',
        'warning_color' => '#f39c12',
        'success_color' => '#27ae60',
        'info_color' => '#3498db',
        'text_color' => '#333333',
        'background_color' => '#f8f9fa',
        'sidebar_color' => '#2c3e50',
        'header_color' => '#ffffff',
    ];

    // Color presets
    public const COLOR_PRESETS = [
        'default' => [
            'name' => 'Default Blue',
            'primary_color' => '#3498db',
            'secondary_color' => '#2ecc71',
            'sidebar_color' => '#2c3e50',
        ],
        'corporate' => [
            'name' => 'Corporate',
            'primary_color' => '#1a365d',
            'secondary_color' => '#2b6cb0',
            'sidebar_color' => '#1a202c',
        ],
        'modern' => [
            'name' => 'Modern Purple',
            'primary_color' => '#6366f1',
            'secondary_color' => '#8b5cf6',
            'sidebar_color' => '#1e1b4b',
        ],
        'nature' => [
            'name' => 'Nature Green',
            'primary_color' => '#059669',
            'secondary_color' => '#10b981',
            'sidebar_color' => '#064e3b',
        ],
        'warm' => [
            'name' => 'Warm Orange',
            'primary_color' => '#ea580c',
            'secondary_color' => '#f97316',
            'sidebar_color' => '#431407',
        ],
        'elegant' => [
            'name' => 'Elegant Dark',
            'primary_color' => '#1f2937',
            'secondary_color' => '#6b7280',
            'sidebar_color' => '#111827',
        ],
        'ocean' => [
            'name' => 'Ocean Teal',
            'primary_color' => '#0891b2',
            'secondary_color' => '#06b6d4',
            'sidebar_color' => '#164e63',
        ],
        'royal' => [
            'name' => 'Royal Gold',
            'primary_color' => '#b45309',
            'secondary_color' => '#d97706',
            'sidebar_color' => '#292524',
        ],
    ];

    // Font options
    public const FONT_OPTIONS = [
        'Inter' => 'Inter (Modern)',
        'Roboto' => 'Roboto',
        'Open Sans' => 'Open Sans',
        'Lato' => 'Lato',
        'Poppins' => 'Poppins',
        'Nunito' => 'Nunito',
        'Source Sans Pro' => 'Source Sans Pro',
        'Montserrat' => 'Montserrat',
    ];

    public const ARABIC_FONT_OPTIONS = [
        'Cairo' => 'Cairo',
        'Tajawal' => 'Tajawal',
        'Almarai' => 'Almarai',
        'IBM Plex Sans Arabic' => 'IBM Plex Sans Arabic',
        'Noto Sans Arabic' => 'Noto Sans Arabic',
        'Amiri' => 'Amiri',
    ];

    // Relationships

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    // Helpers

    public static function getForOrganization(int $organizationId): self
    {
        return Cache::remember("branding.{$organizationId}", 3600, function () use ($organizationId) {
            return static::firstOrCreate(
                ['organization_id' => $organizationId],
                self::DEFAULT_COLORS
            );
        });
    }

    public function getCssVariables(): array
    {
        return [
            '--color-primary' => $this->primary_color,
            '--color-secondary' => $this->secondary_color,
            '--color-accent' => $this->accent_color,
            '--color-danger' => $this->danger_color,
            '--color-warning' => $this->warning_color,
            '--color-success' => $this->success_color,
            '--color-info' => $this->info_color,
            '--color-text' => $this->text_color,
            '--color-background' => $this->background_color,
            '--color-sidebar' => $this->sidebar_color,
            '--color-header' => $this->header_color,
            '--font-family' => $this->font_family,
            '--font-family-arabic' => $this->font_family_arabic,
            '--font-size-base' => $this->base_font_size . 'px',
        ];
    }

    public function generateCssString(): string
    {
        $variables = $this->getCssVariables();
        $css = ":root {\n";

        foreach ($variables as $var => $value) {
            $css .= "  {$var}: {$value};\n";
        }

        $css .= "}\n";

        if ($this->custom_css) {
            $css .= "\n/* Custom CSS */\n" . $this->custom_css;
        }

        return $css;
    }

    public function applyPreset(string $presetKey): void
    {
        if (isset(self::COLOR_PRESETS[$presetKey])) {
            $preset = self::COLOR_PRESETS[$presetKey];
            unset($preset['name']);
            $this->fill($preset);
        }
    }

    public function getLogoUrl(bool $darkMode = false): ?string
    {
        if ($darkMode && $this->logo_dark_url) {
            return $this->logo_dark_url;
        }

        return $this->logo_url;
    }

    public static function clearCache(int $organizationId): void
    {
        Cache::forget("branding.{$organizationId}");
    }

    protected static function booted(): void
    {
        static::saved(function ($branding) {
            self::clearCache($branding->organization_id);
        });
    }
}
