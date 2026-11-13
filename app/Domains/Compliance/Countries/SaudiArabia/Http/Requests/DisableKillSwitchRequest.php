<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\SaudiArabia\Http\Requests;

use App\Domains\Platform\Services\KillSwitch;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DisableKillSwitchRequest extends FormRequest
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
        ];
    }
}
