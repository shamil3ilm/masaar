<?php

declare(strict_types=1);

namespace App\Domains\Referral\Http\Controllers;

use App\Domains\Billing\Services\AccountCredits;
use App\Domains\Organization\Http\Concerns\ResolvesPortalOrganization;
use App\Domains\Referral\Services\ReferralCodes;
use App\Domains\Referral\Services\ReferralProgramme;
use App\Domains\Referral\Services\Referrals;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Portal › Referrals: the organization's referral link and code, what it
 * and the company it refers are given, the companies that signed up with
 * it - by status and date, not by name - and the credit it holds.
 *
 * Administrators only, as its billing is.
 */
class PortalReferralController extends Controller
{
    use ResolvesPortalOrganization;

    public function __construct(
        private readonly ReferralProgramme $programme,
        private readonly ReferralCodes $codes,
        private readonly Referrals $referrals,
        private readonly AccountCredits $credits,
    ) {}

    public function show(Request $request): View
    {
        $organizationId = $this->portalOrganizationId($request, __('Only an administrator of this organization can see its referrals.'));
        $runs = $this->programme->runsFor($organizationId);
        $code = $runs ? $this->codes->for($organizationId)->code : null;

        return view('portal.referrals', [
            'runs' => $runs,
            'code' => $code,
            'link' => $code === null ? null : route('register', ['ref' => $code]),
            'terms' => $this->programme->terms($organizationId),
            'referrals' => $this->referrals->madeBy($organizationId),
            'credits' => $this->credits->of($organizationId),
        ]);
    }
}
