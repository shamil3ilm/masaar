<?php

declare(strict_types=1);

namespace App\Domains\Branding\DTOs;

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
    ) {}

    public function name(): string
    {
        return $this->definition->name ?? 'Masaar';
    }
}
