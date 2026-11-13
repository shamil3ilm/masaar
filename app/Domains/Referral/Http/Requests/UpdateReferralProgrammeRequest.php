<?php

declare(strict_types=1);

namespace App\Domains\Referral\Http\Requests;

use App\Domains\Referral\Services\ReferralProgramme;
use Illuminate\Foundation\Http\FormRequest;

class UpdateReferralProgrammeRequest extends FormRequest
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
        return app(ReferralProgramme::class)->rules($this->all());
    }
}
