<?php

declare(strict_types=1);

namespace App\Domains\Branding\DTOs;

use App\Domains\Branding\Exceptions\InvalidThemeException;
use App\Domains\Branding\Services\Palette;

/**
 * A theme as JSON describes it.
 *
 *     {
 *       "name": "Masaar",
 *       "mode": "light",
 *       "colors": { "primary": "#14B8A6", "navigation": "#1A1F36", "highlight": "#EADBC8" },
 *       "shades": { "primary-600": "#0D9488" },
 *       "dark": { "colors": { "primary": "#2DD4BF" }, "shades": {} }
 *     }
 *
 * Every key is optional: what a theme leaves out it inherits - an
 * organization's from the platform's, the platform's from the shipped one.
 * The colours generate whole ramps; a shade pins one step of a ramp when the
 * generated one is not what the brand guide says. "dark" holds colours and
 * shades that apply only when the page is dark - a brand teal that reads well
 * on white can be too dim on near-black.
 *
 * Values reach a stylesheet, so they are checked before they are kept: a
 * colour is six hex digits and nothing else, which is what stands between a
 * theme and "red;} body{display:none".
 */
final readonly class ThemeDefinition
{
    public const MODES = ['light', 'dark', 'system'];

    private const HEX = '/^#[0-9A-Fa-f]{6}$/';

    /**
     * @param  array<string, string|null>  $colors  primary, navigation, highlight
     * @param  array<string, string>  $shades  "ramp-step" => hex
     */
    private function __construct(
        public ?string $name,
        public ?string $mode,
        public array $colors,
        public array $shades,
        public ?self $dark = null,
    ) {}

    /**
     * The look the application ships with.
     */
    public static function shipped(): self
    {
        return new self('Masaar', 'light', [
            'primary' => '#14B8A6',
            'navigation' => '#1A1F36',
            'highlight' => '#EADBC8',
        ], []);
    }

    public static function empty(): self
    {
        return new self(null, null, ['primary' => null, 'navigation' => null, 'highlight' => null], []);
    }

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws InvalidThemeException listing every problem, not only the first
     */
    public static function fromArray(array $input): self
    {
        return self::parse($input, ['name', 'mode', 'colors', 'shades', 'dark']);
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  list<string>  $known
     *
     * @throws InvalidThemeException
     */
    private static function parse(array $input, array $known, string $prefix = ''): self
    {
        $errors = [];

        foreach (array_diff(array_keys($input), $known) as $unknown) {
            $errors[] = "\"{$unknown}\" is not part of a theme. Use: ".implode(', ', $known).'.';
        }

        $name = $input['name'] ?? null;
        if ($name !== null && (! is_string($name) || trim($name) === '' || mb_strlen($name) > 40 || preg_match('/[\x00-\x1F]/', $name))) {
            $errors[] = 'The name must be 1 to 40 characters on one line.';
        }

        $mode = $input['mode'] ?? null;
        if ($mode !== null && ! in_array($mode, self::MODES, true)) {
            $errors[] = 'The mode must be one of: '.implode(', ', self::MODES).'.';
        }

        $colors = ['primary' => null, 'navigation' => null, 'highlight' => null];
        $given = $input['colors'] ?? [];

        if (! is_array($given)) {
            $errors[] = '"colors" must be an object.';
            $given = [];
        }

        foreach ($given as $key => $value) {
            if (! array_key_exists($key, $colors)) {
                $errors[] = "\"{$key}\" is not a theme colour. Use: primary, navigation, highlight.";
            } elseif ($value !== null && (! is_string($value) || ! preg_match(self::HEX, $value))) {
                $errors[] = "The {$key} colour must be a hex colour such as #14B8A6.";
            } else {
                $colors[$key] = $value === null ? null : strtoupper($value);
            }
        }

        $shades = [];
        $pins = $input['shades'] ?? [];

        if (! is_array($pins)) {
            $errors[] = '"shades" must be an object.';
            $pins = [];
        }

        $palette = new Palette;

        foreach ($pins as $key => $value) {
            [$ramp, $step] = array_pad(explode('-', (string) $key, 2), 2, '');

            if (! array_key_exists($ramp, Palette::RAMPS) || ! in_array($step, $palette->steps($ramp), true)) {
                $errors[] = "\"{$key}\" is not a shade. Use a colour and one of its steps, such as primary-600.";
            } elseif (! is_string($value) || ! preg_match(self::HEX, $value)) {
                $errors[] = "The {$key} shade must be a hex colour such as #0D9488.";
            } else {
                $shades[(string) $key] = strtoupper($value);
            }
        }

        $dark = null;

        if (array_key_exists('dark', $input) && $input['dark'] !== null) {
            if (! is_array($input['dark'])) {
                $errors[] = '"dark" must be an object.';
            } else {
                try {
                    $dark = self::parse($input['dark'], ['colors', 'shades'], 'dark: ');
                } catch (InvalidThemeException $e) {
                    $errors = [...$errors, ...$e->errors];
                }
            }
        }

        if ($errors !== []) {
            throw new InvalidThemeException(array_map(fn (string $e): string => $prefix.$e, $errors));
        }

        return new self($name === null ? null : trim($name), $mode, $colors, $shades, $dark);
    }

    /**
     * This theme with another laid over it: what the other sets wins, what it
     * leaves out is kept.
     */
    public function under(self $over): self
    {
        $colors = [];

        foreach ($this->colors as $key => $mine) {
            $colors[$key] = $over->colors[$key] ?? $mine;
        }

        $dark = match (true) {
            $this->dark === null => $over->dark,
            $over->dark === null => $this->dark,
            default => $this->dark->under($over->dark),
        };

        return new self(
            $over->name ?? $this->name,
            $over->mode ?? $this->mode,
            $colors,
            [...$this->shades, ...$over->shades],
            $dark,
        );
    }

    /**
     * As JSON: only what this theme sets, so an export says what was chosen
     * rather than repeating what is inherited.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'name' => $this->name,
            'mode' => $this->mode,
            'colors' => array_filter($this->colors, fn (?string $c): bool => $c !== null) ?: null,
            'shades' => $this->shades ?: null,
            'dark' => $this->dark?->toArray() ?: null,
        ], fn ($value): bool => $value !== null);
    }
}
