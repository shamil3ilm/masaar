<?php

declare(strict_types=1);

namespace App\Domains\Referral\Http\Controllers;

use App\Domains\Platform\Http\Middleware\AuditAdminAction;
use App\Domains\Referral\Enums\ReferralStatus;
use App\Domains\Referral\Models\Referral;
use App\Domains\Referral\Services\ReferralReport;
use App\Domains\Referral\Services\ReferralRewards;
use App\Domains\Referral\Services\Referrals;
use App\Http\Controllers\Controller;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Admin › Finance › Referrals: the programme's report and every referral,
 * and an admin's hand on one - approve it, adjust a reward, reverse it.
 * Everyone in the console reads it; acting on one takes billing.manage.
 */
class AdminReferralController extends Controller
{
    public function __construct(
        private readonly Referrals $referrals,
        private readonly ReferralRewards $rewards,
        private readonly ReferralReport $report,
    ) {}

    public function index(Request $request): View
    {
        $status = ReferralStatus::tryFrom((string) $request->query('status', ''));

        return view('admin.referrals.index', [
            'summary' => $this->report->summary(),
            'referrals' => $this->referrals->page($status)->withQueryString(),
            'statuses' => ReferralStatus::cases(),
            'status' => $status,
        ]);
    }

    public function show(string $referralId): View
    {
        return view('admin.referrals.show', ['referral' => $this->referral($referralId)]);
    }

    public function approve(Request $request, string $referralId): RedirectResponse
    {
        $referral = $this->referral($referralId);
        $request->attributes->set(AuditAdminAction::TARGET, ['Referral', $referralId]);

        try {
            $this->referrals->approve($referral, (string) $request->user()->id);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('admin.referrals.show', $referralId)->with('success', 'Referral approved.');
    }

    public function reverse(Request $request, string $referralId): RedirectResponse
    {
        $referral = $this->referral($referralId);
        $request->attributes->set(AuditAdminAction::TARGET, ['Referral', $referralId]);

        $reason = (string) $request->validate(['reason' => ['required', 'string', 'max:500']])['reason'];

        try {
            $this->referrals->reverse($referral, $reason, (string) $request->user()->id);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('admin.referrals.show', $referralId)->with('success', 'Referral reversed, with its rewards.');
    }

    public function adjust(Request $request, string $referralId, string $rewardId): RedirectResponse
    {
        $referral = $this->referral($referralId);
        $request->attributes->set(AuditAdminAction::TARGET, ['ReferralReward', $rewardId]);
        $reward = $referral->rewards->firstWhere('id', $rewardId) ?? abort(404);

        $data = $request->validate([
            'value' => ['required', 'numeric', 'min:0', 'max:9999999', 'decimal:0,2'],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        try {
            $this->rewards->adjust($reward, (string) $data['value'], $data['reason'], (string) $request->user()->id);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('admin.referrals.show', $referralId)->with('success', 'Reward adjusted.');
    }

    private function referral(string $referralId): Referral
    {
        return $this->referrals->find($referralId) ?? abort(404);
    }
}
