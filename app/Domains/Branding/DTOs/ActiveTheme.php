<?php

declare(strict_types=1);

namespace App\Domains\Branding\DTOs;

use App\Support\Brand;

/**
 * The theme a page is drawn in: the platform's, with an organization's laid
 * over it where the page belongs to one.
 */
final readonly class ActiveTheme
{
    public function __construct(
        public string $scope,
        public ThemeDefinition $definition,
        public string $stylesheetUrl,
        public string $faviconUrl,
        public ?string $faviconType,
        public ?string $logoUrl,
        // The organization's own uploaded logo, never the platform's: what a
        // document the organization issues may carry.
        public ?string $organizationLogoUrl = null,
        public ?string $logoType = null,
    ) {}

    /** The name a theme was given, else the product's, in the page's language. */
    public function name(): string
    {
        return $this->definition->name ?? Brand::name();
    }

    /**
     * The class <html> carries for the theme's mode: "dark" always dark,
     * "theme-system" dark when the device prefers it, nothing for light.
     */
    public function htmlClass(): string
    {
        return match ($this->definition->mode) {
            'dark' => 'dark',
            'system' => 'theme-system',
            default => '',
        };
    }
}
