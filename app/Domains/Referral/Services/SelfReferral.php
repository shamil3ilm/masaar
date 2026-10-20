<?php

declare(strict_types=1);

namespace App\Domains\Referral\Services;

use App\Domains\Auth\Models\User;
use App\Domains\Organization\Models\Organization;
use Illuminate\Support\Collection;

/**
 * Whether a company referred is really its referrer: the same company, the
 * same VAT or TRN, someone who already works in the referrer, or the same
 * email domain as the referrer's people. A public mail domain
 * (referrals.public_email_domains) says nothing about who someone works
 * for, so it never counts.
 *
 * Asked at sign-up and again when the referral qualifies, since a VAT
 * number is often added after sign-up.
 */
class SelfReferral
{
    /** Why it is the referrer itself, or null when it is not. */
    public function reason(Organization $referrer, Organization $referred, ?User $user = null): ?string
    {
        if ($referrer->is($referred)) {
            return 'A company cannot refer itself.';
        }

        if (filled($referrer->vat_number) && $this->same((string) $referrer->vat_number, (string) $referred->vat_number)) {
            return 'Same VAT or TRN as the referrer.';
        }

        $people = $this->peopleOf($referred, $user);
        $referrers = $referrer->users()->wherePivot('status', 'active')->get(['users.id', 'users.email']);

        if ($people->pluck('id')->intersect($referrers->pluck('id'))->isNotEmpty()) {
            return 'Signed up by someone who works in the referrer.';
        }

        $domains = $referrers->map(fn (User $person): ?string => $this->domain((string) $person->email))->filter()->unique();

        if ($people->map(fn (User $person): ?string => $this->domain((string) $person->email))->filter()->intersect($domains)->isNotEmpty()) {
            return 'Same email domain as the referrer.';
        }

        return null;
    }

    /**
     * @return Collection<int, User>
     */
    private function peopleOf(Organization $organization, ?User $user): Collection
    {
        $people = $organization->users()->get(['users.id', 'users.email']);

        return $user === null ? $people : $people->push($user)->unique('id');
    }

    /** A company's own domain, or null for a public mail provider's. */
    private function domain(string $email): ?string
    {
        $domain = strtolower(trim((string) substr((string) strrchr($email, '@'), 1)));

        return $domain === '' || in_array($domain, (array) config('referrals.public_email_domains', []), true) ? null : $domain;
    }

    private function same(string $a, string $b): bool
    {
        $clean = fn (string $value): string => strtoupper((string) preg_replace('/\s+/', '', $value));

        return $clean($a) !== '' && $clean($a) === $clean($b);
    }
}
