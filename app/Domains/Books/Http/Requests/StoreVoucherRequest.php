<?php

declare(strict_types=1);

namespace App\Domains\Books\Http\Requests;

use App\Domains\Books\Services\Vouchers;
use App\Domains\Organization\Services\TenantResolver;
use Illuminate\Foundation\Http\FormRequest;

class StoreVoucherRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $tenant = app(TenantResolver::class);
        $organization = $tenant->getOrganization();

        if ($organization === null) {
            $orgId = (string) ($this->route('organizationId') ?? session('portal_org_id') ?? '');
            if ($orgId !== '') {
                $organization = $tenant->find($orgId);
            }
        }

        if ($organization === null) {
            return [];
        }

        return app(Vouchers::class)->saleRules($organization);
    }
}
