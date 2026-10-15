<?php

declare(strict_types=1);

namespace App\Domains\Branding\Services;

use App\Domains\Branding\DTOs\ActiveTheme;
use App\Domains\Branding\DTOs\ThemeDefinition;
use App\Domains\Branding\Models\Theme;
use App\Domains\Organization\Http\Middleware\PortalTenant;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;

/**
 * Which theme a page is drawn in, and the stylesheet that draws it.
 *
 * The platform's theme applies everywhere; an organization's is laid over it
 * on that organization's portal pages and documents. Themes are applied as a
 * stylesheet of colour variables loaded after the application's own: the
 * interface reads every colour through those variables, so overriding them
 * recolours it without rebuilding anything, and a stylesheet from this origin
 * is what the content policy already allows - no inline style needed.
 *
 * Only what a theme sets is written out. With nothing customised the
 * stylesheet is empty and the application looks exactly as it ships.
 */
final class ThemeService
{
    public const PLATFORM = 'platform';

    private const CACHE_PREFIX = 'theme:';

    public function __construct(
        private readonly Palette $palette,
        private readonly BrandAssets $assets,
    ) {}

    /**
     * The theme for the page being served: the organization's on a portal
     * page, the platform's everywhere else.
     */
    public function forRequest(Request $request): ActiveTheme
    {
        $organizationId = $request->attributes->get(PortalTenant::ORG_ID);

        return $this->forScope(is_string($organizationId) ? $organizationId : self::PLATFORM);
    }

    public function forScope(string $scope): ActiveTheme
    {
        $platform = $this->row(null);
        $organization = $scope === self::PLATFORM ? null : $this->row($scope);

        $definition = ThemeDefinition::shipped()
            ->under($this->definitionOf($platform))
            ->under($this->definitionOf($organization));

        $version = ($platform['version'] ?? 0).'-'.($organization['version'] ?? 0);
        [$logoOwner, $logo] = $this->owner('logo', $scope, $platform, $organization);
        [$faviconOwner, $favicon] = $this->owner('favicon', $scope, $platform, $organization);

        return new ActiveTheme(
            scope: $scope,
            definition: $definition,
            stylesheetUrl: route('theme.css', ['scope' => $scope, 'v' => $version]),
            faviconUrl: $favicon === null
                ? asset('favicon.svg')
                : route('theme.favicon', ['scope' => $faviconOwner, 'v' => $favicon['version']]),
            faviconType: $favicon['favicon_type'] ?? 'image/svg+xml',
            logoUrl: $logo === null ? null : route('theme.logo', ['scope' => $logoOwner, 'v' => $logo['version']]),
            organizationLogoUrl: $scope !== self::PLATFORM && $logoOwner === $scope
                ? route('theme.logo', ['scope' => $scope, 'v' => $logo['version']])
                : null,
        );
    }

    /**
     * The stylesheet for a scope: colour variables for what its themes set.
     */
    public function stylesheet(string $scope): string
    {
        $custom = $this->definitionOf($this->row(null));

        if ($scope !== self::PLATFORM) {
            $custom = $custom->under($this->definitionOf($this->row($scope)));
        }

        $variables = [];

        foreach (Palette::RAMPS as $ramp => $meta) {
            $hex = $custom->colors[$ramp] ?? null;

            if ($hex !== null) {
                foreach ($this->palette->ramp($ramp, $hex) as $step => $value) {
                    $variables["--color-{$meta['variable']}-{$step}"] = $value;
                }
            }
        }

        foreach ($custom->shades as $key => $hex) {
            [$ramp, $step] = explode('-', $key, 2);
            $variables['--color-'.Palette::RAMPS[$ramp]['variable']."-{$step}"] = $hex;
        }

        if ($variables === []) {
            return "/* No theme is set: the application's own colours apply. */\n";
        }

        $lines = array_map(fn (string $name, string $value): string => "  {$name}: {$value};", array_keys($variables), $variables);

        return ":root {\n".implode("\n", $lines)."\n}\n";
    }

    /**
     * The definition a scope stores, without what it inherits - what its
     * editor shows and what an export writes.
     */
    public function storedDefinition(?string $organizationId): ThemeDefinition
    {
        return $this->definitionOf($this->row($organizationId));
    }

    /**
     * @return array{logo: bool, favicon: bool}
     */
    public function storedAssets(?string $organizationId): array
    {
        $row = $this->row($organizationId);

        return ['logo' => ($row['logo_path'] ?? null) !== null, 'favicon' => ($row['favicon_path'] ?? null) !== null];
    }

    /**
     * Save a scope's theme, with any new or removed logo and favicon.
     *
     * @param  array{logo?: UploadedFile|null, favicon?: UploadedFile|null}  $uploads
     * @param  array{logo?: bool, favicon?: bool}  $remove
     */
    public function save(?string $organizationId, ThemeDefinition $definition, array $uploads = [], array $remove = [], ?string $actor = null): void
    {
        $theme = Theme::query()->firstOrNew(['org_id' => $organizationId]);
        $folder = $organizationId ?? self::PLATFORM;
        $discard = [];

        foreach (['logo', 'favicon'] as $kind) {
            $file = $uploads[$kind] ?? null;

            if ($file instanceof UploadedFile) {
                $stored = $this->assets->store($file, $folder, $kind);
                $discard[] = $theme->{"{$kind}_path"};
                $theme->{"{$kind}_path"} = $stored['path'];
                $theme->{"{$kind}_type"} = $stored['type'];
            } elseif ($remove[$kind] ?? false) {
                $discard[] = $theme->{"{$kind}_path"};
                $theme->{"{$kind}_path"} = null;
                $theme->{"{$kind}_type"} = null;
            }
        }

        $theme->definition = $definition->toArray();
        $theme->version = ($theme->version ?? 0) + 1;
        $theme->updated_by = $actor;
        $theme->save();

        $this->forget($organizationId);

        foreach ($discard as $path) {
            $this->assets->delete($path);
        }
    }

    /**
     * Back to inheriting everything.
     */
    public function reset(?string $organizationId): void
    {
        $theme = Theme::query()->where('org_id', $organizationId)->first();

        if ($theme === null) {
            return;
        }

        $this->assets->delete($theme->logo_path);
        $this->assets->delete($theme->favicon_path);
        $theme->delete();

        $this->forget($organizationId);
    }

    /**
     * The stored file behind a scope's logo or favicon, falling back to the
     * platform's.
     *
     * @param  'logo'|'favicon'  $kind
     * @return array{path: string, type: string}|null
     */
    public function asset(string $scope, string $kind): ?array
    {
        [, $row] = $this->owner($kind, $scope, $this->row(null), $scope === self::PLATFORM ? null : $this->row($scope));

        if ($row === null || ! $this->assets->exists($row["{$kind}_path"])) {
            return null;
        }

        return ['path' => $this->assets->absolutePath($row["{$kind}_path"]), 'type' => $row["{$kind}_type"]];
    }

    /**
     * Whose logo or favicon a scope shows: its own, else the platform's.
     *
     * @param  array<string, mixed>|null  $platform
     * @param  array<string, mixed>|null  $organization
     * @return array{0: string|null, 1: array<string, mixed>|null}
     */
    private function owner(string $kind, string $scope, ?array $platform, ?array $organization): array
    {
        if (($organization["{$kind}_path"] ?? null) !== null) {
            return [$scope, $organization];
        }

        if (($platform["{$kind}_path"] ?? null) !== null) {
            return [self::PLATFORM, $platform];
        }

        return [null, null];
    }

    /**
     * A scope's stored row, cached until it is next saved.
     *
     * @return array<string, mixed>|null
     */
    private function row(?string $organizationId): ?array
    {
        $cached = Cache::rememberForever(self::CACHE_PREFIX.($organizationId ?? self::PLATFORM), function () use ($organizationId) {
            $theme = Theme::query()->where('org_id', $organizationId)->first();

            // false, not null: a null would not be remembered.
            return $theme === null ? false : $theme->only([
                'definition', 'logo_path', 'logo_type', 'favicon_path', 'favicon_type', 'version',
            ]);
        });

        return $cached === false ? null : $cached;
    }

    /**
     * @param  array<string, mixed>|null  $row
     */
    private function definitionOf(?array $row): ThemeDefinition
    {
        return $row === null ? ThemeDefinition::empty() : ThemeDefinition::fromArray($row['definition'] ?? []);
    }

    private function forget(?string $organizationId): void
    {
        Cache::forget(self::CACHE_PREFIX.($organizationId ?? self::PLATFORM));
    }
}
