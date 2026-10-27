<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\SaudiArabia\Http\Controllers;

use App\Domains\Compliance\Countries\SaudiArabia\Reports\ArchiveDownload;
use App\Domains\Organization\Enums\MemberRole;
use App\Domains\Organization\Http\Concerns\ResolvesPortalOrganization;
use App\Domains\Organization\Services\TenantResolver;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * The organization's issued e-invoices for a period as one ZIP - signed and
 * cleared XML, PDFs and a manifest (DocumentArchive) - for its own archive
 * (E-Invoicing Detailed Guidelines 5.5, p26). An owner, an administrator or
 * the accountant takes it; a month or so at a time, since each PDF is drawn.
 */
class PortalArchiveController extends Controller
{
    use ResolvesPortalOrganization;

    /** The longest period one download covers. */
    private const MAX_DAYS = 31;

    public function __construct(
        private readonly TenantResolver $tenant,
        private readonly ArchiveDownload $download,
    ) {}

    /**
     * GET /portal/zatca/archive?from=&to=
     */
    public function download(Request $request): BinaryFileResponse
    {
        $organization = $this->portalOrganization($this->tenant, $request);
        $role = $request->user()?->roleIn((string) $organization->id);

        abort_unless(MemberRole::administers($role) || $role === MemberRole::Accountant->value, 403,
            __('Only an owner, an administrator or the accountant can download the e-invoice archive.'));

        return $this->download->respond($request, $organization, self::MAX_DAYS);
    }
}
