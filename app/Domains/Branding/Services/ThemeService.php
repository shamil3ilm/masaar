<?php

declare(strict_types=1);

namespace App\Domains\Branding\Services;

use App\Domains\Branding\DTOs\ActiveTheme;
use App\Domains\Branding\DTOs\ThemeDefinition;
use App\Domains\Branding\Exceptions\InvalidThemeException;
use App\Domains\Branding\Models\Theme;
use App\Domains\Organization\Http\Middleware\PortalTenant;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Which theme a page is drawn in, and the stylesheet that draws it.
 *
 * The platform's theme applies everywhere; an organization's is laid over it
 * on that organization's portal pages and documents. Themes are applied as a
 * stylesheet of colour variables loaded after the application's own: the
 * interface reads every colour through those variables, so overriding them
 * recolours it without rebuilding anything, and a stylesheet from this origin
 * is what the content policy already allows - no inline style needed. The
 * typeface is chosen the same way, as two font variables.
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
            logoType: $logo['logo_type'] ?? null,
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

        $css = $this->block(':root', [...$this->variables($custom), ...$this->fonts($custom)]);

        // Dark-only colours, for the two ways a page is dark: fixed by the
        // theme (.dark), or left to a device that prefers it (.theme-system).
        if ($custom->dark !== null) {
            $dark = $this->variables($custom->dark);
            $css .= $this->block(':root.dark', $dark);

            if ($dark !== []) {
                $css .= "@media (prefers-color-scheme: dark) {\n".$this->block(':root.theme-system', $dark)."}\n";
            }
        }

        return $css === '' ? "/* No theme is set: the application's own colours apply. */\n" : $css;
    }

    /**
     * The colour variables a definition sets: whole ramps for its colours,
     * then any pinned shades over them.
     *
     * @return array<string, string>
     */
    private function variables(ThemeDefinition $definition): array
    {
        $variables = [];

        foreach (Palette::RAMPS as $ramp => $meta) {
            $hex = $definition->colors[$ramp] ?? null;

            if ($hex !== null) {
                foreach ($this->palette->ramp($ramp, $hex) as $step => $value) {
                    $variables["--color-{$meta['variable']}-{$step}"] = $value;
                }
            }
        }

        foreach ($definition->shades as $key => $hex) {
            [$ramp, $step] = explode('-', $key, 2);
            $variables['--color-'.Palette::RAMPS[$ramp]['variable']."-{$step}"] = $hex;
        }

        return $variables;
    }

    /**
     * The typeface variables a definition sets: one family for Latin and one
     * for Arabic, which resources/css/app.css orders by the page's language.
     *
     * @return array<string, string>
     */
    private function fonts(ThemeDefinition $definition): array
    {
        $font = ThemeDefinition::FONTS[$definition->font ?? ''] ?? null;

        if ($font === null) {
            return [];
        }

        return ['--font-latin' => $font['latin'], '--font-arabic' => $font['arabic']];
    }

    /**
     * @param  array<string, string>  $variables
     */
    private function block(string $selector, array $variables): string
    {
        if ($variables === []) {
            return '';
        }

        $lines = array_map(fn (string $name, string $value): string => "  {$name}: {$value};", array_keys($variables), $variables);

        return "{$selector} {\n".implode("\n", $lines)."\n}\n";
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
        $scope = $organizationId ?? self::PLATFORM;
        $theme = Theme::query()->firstOrNew(['scope' => $scope]);
        $theme->org_id = $organizationId;
        $discard = [];
        $written = [];

        try {
            foreach (['logo', 'favicon'] as $kind) {
                $file = $uploads[$kind] ?? null;

                if ($file instanceof UploadedFile) {
                    $stored = $this->assets->store($file, $scope, $kind);
                    $written[] = $stored['path'];
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
            // Never a version this scope has served before. Counting from the
            // row restarted at 1 after a reset, and a browser that had cached
            // version 1 for a year kept the old colours and logo.
            $theme->version = max(($theme->version ?? 0) + 1, time());
            $theme->updated_by = $actor;
            $theme->save();
        } catch (UniqueConstraintViolationException $e) {
            // Another first save for this scope won the race; ours is not kept.
            $this->discardFiles($written);

            throw new InvalidThemeException(['Someone else saved this branding at the same moment. Reload and try again.']);
        } catch (Throwable $e) {
            // A second upload refused after the first was written left that
            // file on disk with nothing pointing at it.
            $this->discardFiles($written);

            throw $e;
        }

        $this->forget($organizationId);
        $this->discardFiles($discard);
    }

    /**
     * @param  list<string|null>  $paths
     */
    private function discardFiles(array $paths): void
    {
        foreach ($paths as $path) {
            $this->assets->delete($path);
        }
    }

    /**
     * Back to inheriting everything.
     */
    public function reset(?string $organizationId): void
    {
        $theme = Theme::query()->where('scope', $organizationId ?? self::PLATFORM)->first();

        if ($theme === null) {
            return;
        }

        $files = [$theme->logo_path, $theme->favicon_path];

        // Emptied rather than deleted, so its version keeps counting up. A
        // deleted row started again at 1, and a browser holding version 1 for
        // a year showed the old look after the next save.
        $theme->forceFill([
            'definition' => [],
            'logo_path' => null,
            'logo_type' => null,
            'favicon_path' => null,
            'favicon_type' => null,
            'version' => $theme->version + 1,
        ])->save();

        $this->forget($organizationId);
        $this->discardFiles($files);
    }

    /**
     * An organization is gone: its uploaded files and cached theme go too.
     *
     * The database removes the theme row with the organization; the files on
     * disk, still served from their old URLs, and the cached row were left.
     */
    public function forgetOrganization(string $organizationId): void
    {
        $this->assets->deleteFolder($organizationId);
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
     * An organization's own uploaded logo, never the platform's: what goes on
     * a document it issues.
     *
     * @return array{path: string, type: string}|null
     */
    public function organizationLogo(string $organizationId): ?array
    {
        $row = $this->row($organizationId);

        if (($row['logo_path'] ?? null) === null || ! $this->assets->exists($row['logo_path'])) {
            return null;
        }

        return ['path' => $this->assets->absolutePath($row['logo_path']), 'type' => $row['logo_type']];
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
        $key = self::CACHE_PREFIX.($organizationId ?? self::PLATFORM);
        $cached = Cache::get($key);

        if ($cached !== null) {
            return $cached === false ? null : $cached;
        }

        $theme = Theme::query()->where('scope', $organizationId ?? self::PLATFORM)->first();

        if ($theme === null) {
            // Only the platform's absence is remembered. An organization id
            // comes from a public URL, and remembering every one asked about
            // let anyone fill the cache with ids that do not exist.
            if ($organizationId === null) {
                Cache::forever($key, false);
            }

            return null;
        }

        $row = $theme->only(['definition', 'logo_path', 'logo_type', 'favicon_path', 'favicon_type', 'version']);
        Cache::forever($key, $row);

        return $row;
    }

    /**
     * @param  array<string, mixed>|null  $row
     */
    private function definitionOf(?array $row): ThemeDefinition
    {
        if ($row === null) {
            return ThemeDefinition::empty();
        }

        // A stored theme that no longer validates - the rules tightened, the
        // row edited by hand - would otherwise fail every page, sign-in and
        // the editor that could reset it included. It is ignored, loudly.
        try {
            return ThemeDefinition::fromArray($row['definition'] ?? []);
        } catch (InvalidThemeException $e) {
            Log::warning('A stored theme no longer validates and is being ignored', ['errors' => $e->errors]);

            return ThemeDefinition::empty();
        }
    }

    private function forget(?string $organizationId): void
    {
        Cache::forget(self::CACHE_PREFIX.($organizationId ?? self::PLATFORM));
    }
}
