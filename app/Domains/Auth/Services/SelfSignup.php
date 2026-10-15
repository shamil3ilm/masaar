<?php

declare(strict_types=1);

namespace App\Domains\Auth\Services;

use App\Domains\Auth\DTOs\RegistrationData;
use App\Domains\Auth\Models\User;
use App\Domains\Organization\DTOs\AddressFields;
use App\Domains\Organization\DTOs\OrganizationData;
use App\Domains\Organization\Models\Organization;
use App\Domains\Organization\Services\Registrar;
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
 */
class SelfSignup
{
    public function __construct(
        private readonly AccountRegistrar $accounts,
        private readonly Registrar $organizations,
    ) {}

    /**
     * @param  array<string, mixed>  $input  Validated sign-up form
     */
    public function register(array $input): User
    {
        $user = DB::transaction(function () use ($input): User {
            $user = $this->accounts->register(RegistrationData::from($input));

            $user->forceFill([
                'terms_accepted_at' => now(),
                'terms_version' => (string) config('signup.terms_version'),
            ])->save();

            $this->organizations->register($user, new OrganizationData(
                name: (string) $input['company_name'],
                nameAr: $input['company_name_ar'] ?? null,
                country: 'SA',
                vatNumber: (string) $input['vat_number'],
                address: AddressFields::from($input),
                status: Organization::STATUS_PENDING_REVIEW,
            ));

            return $user;
        });

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
