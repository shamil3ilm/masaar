<?php

declare(strict_types=1);

namespace App\Domains\Branding\Http\Requests;

use App\Domains\Branding\DTOs\ThemeDefinition;
use App\Domains\Branding\Exceptions\InvalidThemeException;
use App\Domains\Branding\Services\BrandAssets;
use App\Domains\Branding\Services\Palette;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

/**
 * The branding form: name, mode, colours, logo and favicon.
 *
 * A colour picker always posts a colour, so each one has a "customise" box
 * beside it; unticked, the colour is inherited rather than pinned to whatever
 * the picker happened to show. Who may submit it is the controller's
 * question, since it differs between the platform and an organization.
 */
class ThemeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $image = ['nullable', 'file', 'max:'.BrandAssets::MAX_KILOBYTES];

        return [
            'name' => ['nullable', 'string', 'max:40'],
            'mode' => ['nullable', 'in:'.implode(',', ThemeDefinition::MODES)],
            'colors' => ['nullable', 'array'],
            'colors.*' => ['nullable', 'string'],
            'custom' => ['nullable', 'array'],
            'logo' => $image,
            'favicon' => $image,
            'remove_logo' => ['nullable', 'boolean'],
            'remove_favicon' => ['nullable', 'boolean'],
        ];
    }

    /**
     * The theme the form describes.
     *
     * @throws ValidationException
     */
    public function definition(ThemeDefinition $current): ThemeDefinition
    {
        $colors = [];

        foreach (array_keys(Palette::RAMPS) as $ramp) {
            $colors[$ramp] = $this->boolean("custom.{$ramp}") ? $this->input("colors.{$ramp}") : null;
        }

        try {
            return ThemeDefinition::fromArray([
                'name' => $this->filled('name') ? $this->string('name')->toString() : null,
                'mode' => $this->filled('mode') ? $this->string('mode')->toString() : null,
                'colors' => $colors,
                // Pinned shades and dark-mode colours are edited as JSON;
                // the form keeps them rather than dropping them on save.
                'shades' => $current->shades,
                'dark' => $current->dark?->toArray(),
            ]);
        } catch (InvalidThemeException $e) {
            throw ValidationException::withMessages(['theme' => $e->errors]);
        }
    }
}
