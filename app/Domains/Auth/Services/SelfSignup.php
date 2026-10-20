<?php

declare(strict_types=1);

namespace App\Domains\Auth\Services;

use App\Domains\Auth\DTOs\RegistrationData;
use App\Domains\Auth\Models\User;
use App\Domains\Organization\DTOs\AddressFields;
use App\Domains\Organization\DTOs\OrganizationData;
use App\Domains\Organization\Models\Organization;
use App\Domains\Organization\Rules\CompanyProfileRules;
use App\Domains\Organization\Services\Registrar;
use App\Domains\Referral\Services\Referrals;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * A business signs itself up: an account, its company, and nothing it can
 * file with yet.
 *
 * The account and the company are created together, so a failure part-way
 * leaves neither. The company waits for a platform admin, who checks its
 * details and assigns its plan; until then it can sign in and draft, and
 * cannot onboard with ZATCA or issue. The account waits for its owner to
 * prove the email address is theirs.
 *
 * The company is registered in the country chosen, the default country
 * unless the form offered another. Whatever that country keeps beyond the
 * organization's own columns is saved by its SellerRules, where the company
 * page and the API save it.
 */
class SelfSignup
{
    public function __construct(
        private readonly AccountRegistrar $accounts,
        private readonly Registrar $organizations,
        private readonly Referrals $referrals,
    ) {}

    /**
     * @param  array<string, mixed>  $input  Validated sign-up form
     */
    public function register(array $input): User
    {
        $organization = null;

        $user = DB::transaction(function () use ($input, &$organization): User {
            $user = $this->accounts->register(RegistrationData::from($input));

            $user->forceFill([
                'terms_accepted_at' => now(),
                'terms_version' => (string) config('signup.terms_version'),
            ])->save();

            $jurisdiction = CompanyProfileRules::jurisdiction($input['country'] ?? null);
            $seller = $jurisdiction->sellerRules();

            $organization = $this->organizations->register($user, new OrganizationData(
                name: (string) $input['company_name'],
                nameAr: $input['company_name_ar'] ?? null,
                country: $jurisdiction->code(),
                vatNumber: $seller->vatNumber($input),
                address: AddressFields::from($input),
                status: Organization::STATUS_PENDING_REVIEW,
            ));

            // The form's 'name' is the person's, not the company's: the
            // country keeps only what it stores beyond the organization.
            $seller->registered($organization, $input);

            // What it says it is, for the defaults that suggests.
            if (filled($input['business_type'] ?? null)) {
                $organization->forceFill(['business_type' => (string) $input['business_type']])->save();
            }

            return $user;
        });

        // The company that recommended it, by its code: after the commit and
        // never fatal - a sign-up does not fail over a referral.
        if (filled($input['referral_code'] ?? null) && $organization instanceof Organization) {
            try {
                $this->referrals->recordSignup((string) $input['referral_code'], $organization, $user);
            } catch (Throwable $e) {
                report($e);
            }
        }

        // After the commit, and not fatal: the account exists either way, and
        // the verification page offers to send the link again.
        try {
            $user->sendEmailVerificationNotification();
        } catch (Throwable $e) {
            Log::error('Sign-up: the verification email could not be sent', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
        }

        return $user;
    }
}
