<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\SaudiArabia\Http\Controllers;

use App\Domains\Compliance\Countries\SaudiArabia\Onboarding\EnvironmentSwitch;
use App\Domains\Compliance\Countries\SaudiArabia\Reports\ArchiveDownload;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * A platform admin downloads an organization's e-invoice archive for a
 * period (DocumentArchive) - for an authority's request or a restore check.
 * The export is on the organization's activity record (ArchiveDownload).
 */
class AdminArchiveController extends Controller
{
    /** Longer than the portal's month: a year's documents at most. */
    private const MAX_DAYS = 366;

    public function __construct(
        private readonly EnvironmentSwitch $organizations,
        private readonly ArchiveDownload $download,
    ) {}

    /**
     * GET /admin/organizations/{id}/zatca-archive?from=&to=
     */
    public function download(Request $request, string $id): BinaryFileResponse
    {
        $organization = $this->organizations->organization($id) ?? abort(404);

        return $this->download->respond($request, $organization, self::MAX_DAYS);
    }
}
