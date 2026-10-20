<?php

declare(strict_types=1);

namespace App\Domains\Referral\Http\Controllers;

use App\Domains\Billing\Enums\BillingCurrency;
use App\Domains\Platform\Http\Middleware\AuditAdminAction;
use App\Domains\Referral\Enums\QualifyingEvent;
use App\Domains\Referral\Enums\RewardKind;
use App\Domains\Referral\Services\ReferralCodes;
use App\Domains\Referral\Services\ReferralProgramme;
use App\Domains\Referral\Services\Referrals;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Admin › Finance › Referrals › Settings: the programme's terms - on or off,
 * each side's reward, the qualifying event, the caps and the expiry - and
 * a partner's or reseller's own terms. A change applies to companies that
 * sign up from then on; a referral keeps the terms it signed up under.
 */
class AdminReferralSettingsController extends Controller
{
    public function __construct(
        private readonly ReferralProgramme $programme,
        private readonly Referrals $referrals,
        private readonly ReferralCodes $codes,
    ) {}

    public function edit(Request $request): View
    {
        $search = trim((string) $request->query('q', ''));

        return view('admin.referrals.settings', [
            'terms' => $this->programme->terms(),
            'search' => $search,
            'found' => $search === '' ? collect() : $this->referrals->organizations($search),
            ...$this->choices(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $request->attributes->set(AuditAdminAction::TARGET, ['Customization', null]);

        $this->programme->savePlatform($request->validate($this->programme->rules($request->all())), (string) $request->user()->id);

        return redirect()->route('admin.referrals.settings')->with('success', 'Referral terms saved. They apply to companies that sign up from now on.');
    }

    /** One organization's own terms, its code, and the referrals it made. */
    public function organization(string $id): View
    {
        $organization = $this->referrals->organization($id) ?? abort(404);

        return view('admin.referrals.organization', [
            'organization' => $organization,
            'own' => $this->programme->ownTerms($id),
            'terms' => $this->programme->terms($id),
            'code' => $this->codes->for($id)->code,
            'made' => $this->referrals->madeBy($id),
            ...$this->choices(),
        ]);
    }

    public function updateOrganization(Request $request, string $id): RedirectResponse
    {
        $this->referrals->organization($id) ?? abort(404);

        $this->programme->saveOrganization($id, $request->validate($this->programme->rules($request->all())), (string) $request->user()->id);

        return redirect()->route('admin.referrals.organization', $id)->with('success', 'Its own terms saved. They apply to companies it refers from now on.');
    }

    public function resetOrganization(Request $request, string $id): RedirectResponse
    {
        $this->referrals->organization($id) ?? abort(404);

        $this->programme->resetOrganization($id, (string) $request->user()->id);

        return redirect()->route('admin.referrals.organization', $id)->with('success', 'It follows the programme\'s terms again.');
    }

    /**
     * @return array{kinds: list<RewardKind>, events: list<QualifyingEvent>, currencies: list<string>}
     */
    private function choices(): array
    {
        return ['kinds' => RewardKind::cases(), 'events' => QualifyingEvent::cases(), 'currencies' => BillingCurrency::codes()];
    }
}
