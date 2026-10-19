<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\UnitedArabEmirates\Http\Controllers;

use App\Domains\Compliance\Countries\UnitedArabEmirates\Models\ProviderAppointment;
use App\Domains\Compliance\Countries\UnitedArabEmirates\Support\ProviderAppointments;
use App\Domains\Compliance\Countries\UnitedArabEmirates\Support\UaePortal;
use App\Domains\Compliance\Networks\Peppol\Gateways\AspGatewayResolver;
use App\Domains\Organization\Http\Concerns\ResolvesPortalOrganization;
use App\Domains\Organization\Services\TenantResolver;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * A UAE organization records the service provider it appointed, on its
 * company page: which one (by purpose, among those the platform works with),
 * whether the appointment is done, when, its reference, and the organization's
 * own last date if its revenue band sets another (UAE Electronic Invoicing
 * Guidelines v1.1, p14, p19, p38-39). Its administrators only.
 */
class PortalProviderAppointmentController extends Controller
{
    use ResolvesPortalOrganization;

    public function __construct(
        private readonly TenantResolver $tenant,
        private readonly ProviderAppointments $appointments,
    ) {}

    /**
     * POST /portal/company/service-provider
     */
    public function update(Request $request): RedirectResponse
    {
        $organization = $this->portalOrganization($this->tenant, $request, __('Only an administrator of this organization can change its details.'));
        abort_unless(UaePortal::offers($organization), 404);

        $validated = $request->validate([
            'provider' => ['required', 'string', Rule::in(array_keys(AspGatewayResolver::configured()))],
            'status' => ['required', Rule::in([ProviderAppointment::PENDING, ProviderAppointment::APPOINTED])],
            'appointed_at' => ['nullable', 'required_if:status,'.ProviderAppointment::APPOINTED, 'date', 'before_or_equal:today'],
            'reference' => ['nullable', 'string', 'max:255'],
            'deadline_on' => ['nullable', 'date'],
        ]);

        $this->appointments->record($organization, $validated, (string) $request->user()?->id);

        return redirect()->route('portal.company.edit')->with('success', __('Service provider appointment saved.'));
    }
}
