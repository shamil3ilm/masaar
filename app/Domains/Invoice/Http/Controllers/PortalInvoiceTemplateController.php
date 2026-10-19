<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Http\Controllers;

use App\Domains\Compliance\Engine\Contracts\Presentation;
use App\Domains\Compliance\Engine\Services\JurisdictionResolver;
use App\Domains\Invoice\Services\InvoicePdf;
use App\Domains\Invoice\Services\InvoicePrintView;
use App\Domains\Invoice\Templates\InvoiceTemplates;
use App\Domains\Organization\Enums\MemberRole;
use App\Domains\Organization\Http\Middleware\PortalTenant;
use App\Domains\Organization\Models\Organization;
use App\Domains\Organization\Services\BranchService;
use App\Domains\Organization\Services\TenantResolver;
use App\Domains\Platform\Customization\Level;
use App\Domains\Platform\Customization\Scope;
use Illuminate\Http\Request;

/**
 * Portal › Branding › Invoice template: the organization's own over the
 * platform's, and a branch's own over the organization's (?branch=).
 *
 * Its administrators only, as for its branding. The organization is the one
 * the session works in, and a branch is one of its own or nothing.
 */
class PortalInvoiceTemplateController extends InvoiceTemplateController
{
    public function __construct(
        InvoiceTemplates $templates,
        InvoicePrintView $printView,
        InvoicePdf $pdf,
        private readonly TenantResolver $tenant,
        private readonly BranchService $branches,
        private readonly JurisdictionResolver $jurisdictions,
    ) {
        parent::__construct($templates, $printView, $pdf);
    }

    protected function owner(Request $request): array
    {
        $organizationId = $request->attributes->get(PortalTenant::ORG_ID);

        abort_unless(is_string($organizationId), 404);
        abort_unless(MemberRole::administers($request->user()?->roleIn($organizationId)), 403, __('Only an administrator of this organization can change its invoice template.'));

        $organization = $this->tenant->getOrganization() ?? abort(404);
        $branchId = $this->branch($request);

        if ($branchId !== null) {
            abort_unless($this->branches->belongsTo($branchId, $organizationId), 404);

            return ['level' => Level::Branch, 'scope' => Scope::branch($organizationId, $branchId), 'organization' => $organization];
        }

        return ['level' => Level::Organization, 'scope' => Scope::organization($organizationId), 'organization' => $organization];
    }

    /** The organization's country, whether or not it is switched on. */
    protected function country(?Organization $organization): ?Presentation
    {
        return $this->jurisdictions->forRules($organization)->presentation();
    }

    protected function extra(Request $request, array $owner): array
    {
        return [
            'branches' => $this->branches->list((string) $owner['organization']?->id),
            'branch' => $this->branch($request),
        ];
    }

    protected function query(Request $request): array
    {
        $branch = $this->branch($request);

        return $branch === null ? [] : ['branch' => $branch];
    }

    protected function route(): string
    {
        return 'portal.invoice-template';
    }

    protected function view(): string
    {
        return 'portal.invoice-template';
    }

    private function branch(Request $request): ?string
    {
        $branch = $request->input('branch');

        return is_string($branch) && $branch !== '' ? $branch : null;
    }
}
