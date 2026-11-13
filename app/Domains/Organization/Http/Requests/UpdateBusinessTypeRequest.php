<?php

declare(strict_types=1);

namespace App\Domains\Organization\Http\Requests;

use App\Domains\Organization\Rules\CompanyProfileRules;
use App\Domains\Organization\Services\BusinessTypes;
use App\Domains\Organization\Services\TenantResolver;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBusinessTypeRequest extends FormRequest
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
        $organization = app(TenantResolver::class)->getOrganization();
        $offered = [];

        if ($organization !== null) {
            $types = app(BusinessTypes::class)->offered(
                CompanyProfileRules::jurisdiction($organization->country),
                $organization->business_type,
                (string) $organization->id,
            );
            $offered = array_map(fn ($type): string => $type->key, $types);
        }

        return [
            'business_type' => ['nullable', 'string', Rule::in($offered)],
        ];
    }
}
