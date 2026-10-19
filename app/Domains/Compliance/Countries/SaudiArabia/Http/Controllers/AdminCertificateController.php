<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\SaudiArabia\Http\Controllers;

use App\Domains\Compliance\Countries\SaudiArabia\Reports\CertificateVersions;
use App\Domains\Compliance\Countries\SaudiArabia\Signing\RevocationApprovals;
use App\Http\Controllers\Controller;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Every device certificate on the platform, and marking one revoked.
 *
 * The daily check reads the CA's revocation lists; this is for what reaches
 * the platform before the list does - ZATCA confirming a revocation, a key
 * known to be compromised. Marking a certificate revoked stops its device
 * signing and files nothing more with it, so it is a POST behind a
 * confirmation and a reason, audited, and put to RevocationApprovals: the
 * platform's approvals, which hold it for a second admin as shipped.
 *
 * Reachable only behind platform.admin.
 */
class AdminCertificateController extends Controller
{
    public function __construct(
        private readonly RevocationApprovals $approvals,
        private readonly CertificateVersions $versions,
    ) {}

    public function index(): View
    {
        return view('countries.saudi-arabia.admin.certificates', ['versions' => $this->versions->all()]);
    }

    public function revoke(Request $request, int $version): RedirectResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']]);

        $record = $this->versions->find($version);

        try {
            $outcome = $this->approvals->submit($record, $validated['reason'], $request->user());
        } catch (DomainException $e) {
            return redirect()->route('admin.certificates')->with('error', $e->getMessage());
        }

        if ($outcome->waits()) {
            return redirect()->route('admin.approvals.show', $outcome->request->id)
                ->with('success', __('Sent for approval. Nothing changes until it is approved.'));
        }

        return redirect()->route('admin.certificates')->with('success', $outcome->result->message);
    }
}
