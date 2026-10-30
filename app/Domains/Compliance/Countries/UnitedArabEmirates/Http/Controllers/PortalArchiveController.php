<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\UnitedArabEmirates\Http\Controllers;

use App\Domains\Compliance\Countries\UnitedArabEmirates\Reports\ArchiveDownload;
use App\Domains\Compliance\Countries\UnitedArabEmirates\Support\UaePortal;
use App\Domains\Organization\Enums\MemberRole;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * The organization's issued UAE e-invoices for a period as one ZIP - each
 * PINT AE document as sent, PDFs and a manifest (DocumentArchive) - for its
 * own records. An owner, an administrator or the accountant takes it; a
 * month or so at a time, since each PDF is drawn. A 404 for an organization
 * outside the UAE (UaePortal).
 */
class PortalArchiveController extends Controller
{
    /** The longest period one download covers. */
    private const MAX_DAYS = 31;

    public function __construct(
        private readonly UaePortal $portal,
        private readonly ArchiveDownload $download,
    ) {}

    /**
     * GET /portal/uae/archive?from=&to=
     */
    public function download(Request $request): BinaryFileResponse
    {
        $organization = $this->portal->organization();
        $role = $request->user()?->roleIn((string) $organization->id);

        abort_unless(MemberRole::administers($role) || $role === MemberRole::Accountant->value, 403,
            __('Only an owner, an administrator or the accountant can download the e-invoice archive.'));

        return $this->download->respond($request, $organization, self::MAX_DAYS);
    }
}
