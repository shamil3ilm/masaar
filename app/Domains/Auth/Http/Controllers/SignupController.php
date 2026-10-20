<?php

declare(strict_types=1);

namespace App\Domains\Auth\Http\Controllers;

use App\Domains\Auth\Http\Requests\SignupRequest;
use App\Domains\Auth\Services\SelfSignup;
use App\Domains\Auth\Services\SignInRecorder;
use App\Domains\Organization\DTOs\BusinessType;
use App\Domains\Organization\Rules\CompanyProfileRules;
use App\Domains\Organization\Services\BusinessTypes;
use App\Domains\Referral\Services\ReferralCodes;
use App\Domains\Referral\Services\ReferralProgramme;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * A business signs itself up from the browser.
 *
 * It leaves signed in, with a link on its way to prove the email address,
 * and a company waiting for a platform admin to review it.
 */
class SignupController extends Controller
{
    public function __construct(
        private readonly SelfSignup $signup,
        private readonly SignInRecorder $signIns,
        private readonly BusinessTypes $businessTypes,
        private readonly ReferralProgramme $programme,
    ) {}

    public function create(Request $request): View|RedirectResponse
    {
        abort_unless((bool) config('signup.enabled'), 404);

        // A referral link (?ref=) fills the code in, and asks for it even
        // where only a partner's own terms run the programme.
        $referralCode = ReferralCodes::normalise((string) $request->query('ref', ''));

        if (Auth::check()) {
            return redirect()->route('portal.dashboard');
        }

        $countries = CompanyProfileRules::countries();
        $default = CompanyProfileRules::jurisdiction(null)->code();
        $chosen = old('country');
        $preferred = in_array($default, $countries, true) ? $default : ($countries[0] ?? $default);

        return view('auth.register', [
            'termsUrl' => config('signup.terms_url'),
            // The default country's own form where it is all there is; the
            // chooser, listing only the countries offered, anywhere else.
            'choice' => $countries !== [$default],
            'countries' => $countries,
            'sections' => $this->sections($countries, $default),
            'country' => in_array($chosen, $countries, true) ? $chosen : $preferred,
            // Whether the company's Arabic name is asked for as required:
            // where the preferred country's invoices must be in Arabic.
            'arabicName' => CompanyProfileRules::jurisdiction($preferred)->languageRules()->requiresArabic(),
            'referrals' => $referralCode !== '' || filled(old('referral_code')) || $this->programme->terms()->enabled,
            'referralCode' => $referralCode,
        ]);
    }

    public function store(SignupRequest $request): RedirectResponse
    {
        abort_unless((bool) config('signup.enabled'), 404);

        $user = $this->signup->register($request->validated());

        Auth::login($user);
        $request->session()->regenerate();

        $this->signIns->succeeded($user, 'console', ['signup' => true]);

        return redirect()->route('verification.notice');
    }

    /**
     * Each country offered, the default first: its code, its name, the system
     * it files through (which the chooser names), the form section its
     * module provides, what happens after sign-up there and the business
     * types it serves.
     *
     * @param  list<string>  $countries
     * @return list<array{code: string, name: string, system: string, view: string, next: string, businessTypes: list<BusinessType>}>
     */
    private function sections(array $countries, string $default): array
    {
        usort($countries, fn (string $a, string $b): int => [$a !== $default, $a] <=> [$b !== $default, $b]);

        return array_map(function (string $code): array {
            $jurisdiction = CompanyProfileRules::jurisdiction($code);

            return [
                'code' => $code,
                'name' => $jurisdiction->name(),
                'system' => $jurisdiction->filingSystem(),
                'view' => $jurisdiction->sellerRules()->signupView(),
                'next' => $jurisdiction->sellerRules()->signupNext(),
                'businessTypes' => $this->businessTypes->offered($jurisdiction),
            ];
        }, $countries);
    }
}
