<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Peppol\Http\Requests;

use App\Domains\Organization\Rules\CompanyProfileRules;
use App\Domains\Organization\Services\TenantResolver;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A UAE seller's details, every one of them: CompanyProfileRules for 'AE',
 * as the portal checks them too.
 */
class SaveUaeProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return CompanyProfileRules::rules('AE', complete: true, existing: app(TenantResolver::class)->getOrganization());
    }

    public function messages(): array
    {
        return CompanyProfileRules::messages();
    }
}
