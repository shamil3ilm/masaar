<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\UnitedArabEmirates\Presentation;

use App\Domains\Compliance\Engine\Contracts\Devices;
use App\Domains\Compliance\Engine\DTOs\Panel;

/**
 * A UAE branch registers no device of its own: the organization sends
 * through its accredited service provider under one Peppol endpoint, and a
 * branch is how it groups its invoices, not something the network knows.
 */
class DeviceStatus implements Devices
{
    public function branchStatus(string $organizationId, string $branchId): ?Panel
    {
        return null;
    }

    public function refusal(string $organizationId, string $branchId): ?string
    {
        return null;
    }

    public function holds(string $organizationId, string $branchId, string $credential): bool
    {
        return false;
    }
}
