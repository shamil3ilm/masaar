<?php

declare(strict_types=1);

namespace App\Domains\Platform\Http\Requests;

use App\Domains\Platform\Access\Feature;
use App\Domains\Platform\Models\FeatureOverride;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateFeatureOverrideRequest extends FormRequest
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
        return [
            'feature' => ['required', 'string', Rule::in(array_map(
                fn (Feature $feature): string => $feature->value,
                array_filter(Feature::cases(), fn (Feature $feature): bool => $feature->overridable()),
            ))],
            'state' => ['required', 'string', Rule::in(['default', FeatureOverride::OFF, FeatureOverride::ON])],
            'reason' => ['required', 'string', 'max:500'],
            'subject' => ['nullable', 'string', 'max:60'],
        ];
    }
}
