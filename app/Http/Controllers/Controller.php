<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Core\Branch;
use App\Models\Core\Organization;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

abstract class Controller
{
    use ApiResponse;

    protected function organization(Request $request): ?Organization
    {
        return $request->attributes->get('organization');
    }

    protected function branch(Request $request): ?Branch
    {
        return $request->attributes->get('branch');
    }

    protected function organizationId(Request $request): ?int
    {
        return $this->organization($request)?->id ?? auth()->user()?->organization_id;
    }

    protected function branchId(Request $request): ?int
    {
        return $this->branch($request)?->id;
    }
}
