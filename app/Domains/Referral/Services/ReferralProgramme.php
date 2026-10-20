<?php

declare(strict_types=1);

namespace App\Domains\Referral\Services;

use App\Domains\Billing\Enums\BillingCurrency;
use App\Domains\Platform\Customization\Customizations;
use App\Domains\Platform\Customization\Level;
use App\Domains\Platform\Customization\Scope;
use App\Domains\Referral\DTOs\ReferralTerms;
use App\Domains\Referral\Enums\QualifyingEvent;
use App\Domains\Referral\Enums\RewardKind;
use Closure;
use Illuminate\Validation\Rule;

/**
 * The programme's terms: the platform's (Admin › Finance › Referrals), and
 * a partner's or reseller's own over them, each a full set of terms. Kept
 * as one customization (ReferralTerms::KEY), so every change is audited
 * like any setting; a referral keeps the terms its referrer had when the
 * company signed up, so a change applies to new referrals only.
 */
class ReferralProgramme
{
    public function __construct(
        private readonly Customizations $customizations,
    ) {}

    /** The terms a referrer's code carries: its own, or the platform's. */
    public function terms(?string $organizationId = null): ReferralTerms
    {
        $scope = $organizationId === null ? Scope::platform() : Scope::organization($organizationId);

        return ReferralTerms::fromArray((array) $this->customizations->get(ReferralTerms::KEY, $scope));
    }

    /** Whether an organization may refer others: the programme runs for it. */
    public function runsFor(?string $organizationId): bool
    {
        return $organizationId !== null && $organizationId !== '' && $this->terms($organizationId)->enabled;
    }

    /** An organization's own terms, or null where it has the platform's. */
    public function ownTerms(string $organizationId): ?ReferralTerms
    {
        $own = $this->customizations->own(ReferralTerms::KEY, Level::Organization, Scope::organization($organizationId));

        return is_array($own) && $own !== [] ? ReferralTerms::fromArray([...$this->terms()->toArray(), ...$own]) : null;
    }

    /**
     * @param  array<string, mixed>  $validated  as rules() checked them
     */
    public function savePlatform(array $validated, string $actorId): void
    {
        $this->customizations->set(ReferralTerms::KEY, Level::Platform, Scope::platform(), $this->values($validated), $actorId);
    }

    /**
     * A partner's or reseller's own terms: every field, so a later change to
     * the platform's leaves them as agreed.
     *
     * @param  array<string, mixed>  $validated
     */
    public function saveOrganization(string $organizationId, array $validated, string $actorId): void
    {
        $this->customizations->set(ReferralTerms::KEY, Level::Organization, Scope::organization($organizationId), $this->values($validated), $actorId);
    }

    /** Back to the platform's terms. */
    public function resetOrganization(string $organizationId, string $actorId): void
    {
        $this->customizations->reset(ReferralTerms::KEY, Level::Organization, Scope::organization($organizationId), $actorId);
    }

    /**
     * The terms form's rules, for the input they check.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, list<mixed>>
     */
    public function rules(array $input): array
    {
        $rules = [
            'enabled' => ['required', 'boolean'],
            'qualifying_event' => ['required', Rule::enum(QualifyingEvent::class)],
            'yearly_cap' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'credit_cap' => ['nullable', 'numeric', 'min:0', 'max:9999999', 'decimal:0,2'],
            'expiry_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
        ];

        foreach (['referrer', 'referred'] as $side) {
            $rules[$side.'_kind'] = ['required', Rule::enum(RewardKind::class), $this->creditGiven((array) ($input[$side.'_credit'] ?? []))];
            $rules[$side.'_percent'] = ['nullable', 'required_if:'.$side.'_kind,percent_off', 'integer', 'min:1', 'max:100'];
            $rules[$side.'_months'] = ['nullable', 'required_if:'.$side.'_kind,free_months', 'integer', 'min:1', 'max:24'];
            $rules[$side.'_credit'] = ['nullable', 'array'];
            $rules[$side.'_credit.*'] = ['nullable', 'numeric', 'min:0', 'max:9999999', 'decimal:0,2'];
        }

        return $rules;
    }

    /**
     * A fixed credit needs an amount in at least one currency.
     *
     * @param  array<mixed>  $credit  the side's amounts, as sent
     */
    private function creditGiven(array $credit): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($credit): void {
            if ($value !== RewardKind::Credit->value) {
                return;
            }

            $amounts = array_filter($credit, fn (mixed $amount): bool => is_numeric($amount) && (float) $amount > 0);

            if ($amounts === []) {
                $fail('Give the credit an amount in at least one currency.');
            }
        };
    }

    /**
     * The form's values as the record keeps them.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function values(array $validated): array
    {
        $number = fn (string $field): ?int => isset($validated[$field]) && $validated[$field] !== '' ? (int) $validated[$field] : null;
        $credit = fn (string $side): array => array_map(
            fn (mixed $amount): string => number_format((float) $amount, 2, '.', ''),
            array_filter(
                array_intersect_key((array) ($validated[$side.'_credit'] ?? []), array_flip(BillingCurrency::codes())),
                fn (mixed $amount): bool => is_numeric($amount) && (float) $amount > 0,
            ),
        );

        $values = ['enabled' => (bool) $validated['enabled'], 'qualifying_event' => (string) $validated['qualifying_event']];

        foreach (['referrer', 'referred'] as $side) {
            $values[$side.'_kind'] = (string) $validated[$side.'_kind'];
            $values[$side.'_percent'] = $number($side.'_percent');
            $values[$side.'_credit'] = $credit($side);
            $values[$side.'_months'] = $number($side.'_months');
        }

        return [
            ...$values,
            'yearly_cap' => $number('yearly_cap'),
            'credit_cap' => isset($validated['credit_cap']) && $validated['credit_cap'] !== '' ? number_format((float) $validated['credit_cap'], 2, '.', '') : null,
            'expiry_days' => $number('expiry_days'),
        ];
    }
}
