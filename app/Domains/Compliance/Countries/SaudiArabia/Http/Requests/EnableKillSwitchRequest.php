<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\SaudiArabia\Http\Requests;

use App\Domains\Platform\Services\KillSwitch;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EnableKillSwitchRequest extends FormRequest
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
            'switch' => ['required', 'string', Rule::in(KillSwitch::SWITCHES)],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
            'minutes' => ['nullable', 'integer', 'min:1', 'max:1440'],
        ];
    }
}
