<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\UnitedArabEmirates\Http\Controllers;

use App\Domains\Compliance\Countries\UnitedArabEmirates\Support\EinvoicingStart;
use App\Domains\Compliance\Countries\UnitedArabEmirates\Support\UaePortal;
use App\Domains\Organization\Http\Concerns\ResolvesPortalOrganization;
use App\Domains\Organization\Services\TenantResolver;
use App\Http\Controllers\Controller;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * A UAE organization sets the date its e-invoicing starts, on its company
 * page: from then every invoice goes through its service provider; before
 * it, invoices are tax invoices kept here and never transmitted (UAE
 * Electronic Invoicing Guidelines v1.1, 6.1, 8.2-8.3). Its administrators
 * only; EinvoicingStart audits the change.
 */
class PortalEinvoicingStartController extends Controller
{
    use ResolvesPortalOrganization;

    public function __construct(
        private readonly TenantResolver $tenant,
        private readonly EinvoicingStart $start,
    ) {}

    /**
     * POST /portal/company/einvoicing-start
     */
    public function update(Request $request): RedirectResponse
    {
        $organization = $this->portalOrganization($this->tenant, $request, __('Only an administrator of this organization can change its details.'));
        abort_unless(UaePortal::offers($organization), 404);

        $validated = $request->validate([
            'einvoicing_start' => ['nullable', 'date_format:Y-m-d'],
        ]);

        try {
            $this->start->set($organization, $validated['einvoicing_start'] ?? null, (string) $request->user()?->id);
        } catch (DomainException $e) {
            return redirect()->route('portal.company.edit')->with('error', $e->getMessage());
        }

        return redirect()->route('portal.company.edit')->with('success', __('E-invoicing start date saved.'));
    }
}
