<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\SaudiArabia\Http\Controllers;

use App\Domains\Compliance\Countries\SaudiArabia\Reports\CertificateVersions;
use App\Domains\Compliance\Countries\SaudiArabia\Signing\CertificateRevocations;
use App\Http\Controllers\Controller;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * Every device certificate on the platform, and marking one revoked.
 *
 * The daily check reads the CA's revocation lists; this is for what reaches
 * the platform before the list does - ZATCA confirming a revocation, a key
 * known to be compromised. Marking a certificate revoked stops its device
 * signing and files nothing more with it, so it is a POST behind a
 * confirmation and a reason, audited, and put to RevocationApprovals - the
 * hook an approval policy binds to.
 *
 * Reachable only behind platform.admin.
 */
class AdminCertificateController extends Controller
{
    public function __construct(
        private readonly CertificateRevocations $revocations,
        private readonly CertificateVersions $versions,
    ) {}

    public function index(): View
    {
        return view('admin.certificates.index', ['versions' => $this->versions->all()]);
    }

    public function revoke(Request $request, int $version): RedirectResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']]);

        $record = $this->versions->find($version);

        try {
            $done = $this->revocations->markRevoked($record, $validated['reason'], (string) Auth::id());
        } catch (DomainException $e) {
            return redirect()->route('admin.certificates')->with('error', $e->getMessage());
        }

        return redirect()->route('admin.certificates')->with('success', $done
            ? "Version {$record->version} is recorded as revoked. Nothing more is filed with it."
            : "The revocation of version {$record->version} is waiting for approval.");
    }
}
