<?php

declare(strict_types=1);

namespace App\Domains\Platform\Http\Requests;

use App\Domains\Compliance\Engine\Services\JurisdictionRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCountrySwitchRequest extends FormRequest
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
            'country' => ['required', 'string', Rule::in(app(JurisdictionRegistry::class)->registered())],
            'enabled' => ['required', 'boolean'],
        ];
    }
}
