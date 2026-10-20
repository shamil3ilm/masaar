<?php

declare(strict_types=1);

namespace App\Domains\Referral\DTOs;

use App\Domains\Referral\Enums\QualifyingEvent;
use App\Domains\Referral\Enums\RewardKind;

/**
 * The referral programme's terms: whether it runs, what each side is given,
 * what the company referred has to do first, and the limits.
 *
 * One customization (referrals.terms), a record: the platform's terms, set
 * in Admin › Finance › Referrals, and an organization's own over them for a
 * partner or reseller. A referral keeps the terms its referrer had when the
 * company signed up (toArray), so a change applies to new referrals only.
 *
 *   yearlyCap   rewards a referrer may receive in a calendar year; null, no cap
 *   creditCap   the most usable credit a company may hold in a currency; a
 *               credit that would pass it is cut to fit, or withheld
 *   expiryDays  days a credit can be used; null, it does not expire
 */
final readonly class ReferralTerms
{
    public const KEY = 'referrals.terms';

    /** The record's fields. */
    public const FIELDS = [
        'enabled',
        'referrer_kind', 'referrer_percent', 'referrer_credit', 'referrer_months',
        'referred_kind', 'referred_percent', 'referred_credit', 'referred_months',
        'qualifying_event', 'yearly_cap', 'credit_cap', 'expiry_days',
    ];

    /**
     * Off until an admin turns it on; one free month each side, once the
     * company referred pays its first invoice; credit usable for a year.
     */
    public const DEFAULTS = [
        'enabled' => false,
        'referrer_kind' => 'free_months',
        'referrer_percent' => 10,
        'referrer_credit' => [],
        'referrer_months' => 1,
        'referred_kind' => 'free_months',
        'referred_percent' => 10,
        'referred_credit' => [],
        'referred_months' => 1,
        'qualifying_event' => 'first_paid',
        'yearly_cap' => null,
        'credit_cap' => null,
        'expiry_days' => 365,
    ];

    public function __construct(
        public bool $enabled,
        public SideReward $referrer,
        public SideReward $referred,
        public QualifyingEvent $qualifyingEvent,
        public ?int $yearlyCap = null,
        public ?string $creditCap = null,
        public ?int $expiryDays = null,
    ) {}

    /**
     * @param  array<string, mixed>  $values  the record, as the customization or a referral keeps it
     */
    public static function fromArray(array $values): self
    {
        $values = [...self::DEFAULTS, ...$values];
        $number = fn (string $field): ?int => is_numeric($values[$field] ?? null) ? (int) $values[$field] : null;
        $side = fn (string $prefix): SideReward => new SideReward(
            kind: RewardKind::tryFrom((string) $values[$prefix.'_kind']) ?? RewardKind::None,
            percent: $number($prefix.'_percent'),
            credit: array_map('strval', array_filter((array) $values[$prefix.'_credit'], 'is_numeric')),
            months: $number($prefix.'_months'),
        );

        return new self(
            enabled: (bool) $values['enabled'],
            referrer: $side('referrer'),
            referred: $side('referred'),
            qualifyingEvent: QualifyingEvent::tryFrom((string) $values['qualifying_event']) ?? QualifyingEvent::FirstPaid,
            yearlyCap: $number('yearly_cap'),
            creditCap: is_numeric($values['credit_cap'] ?? null) ? number_format((float) $values['credit_cap'], 2, '.', '') : null,
            expiryDays: $number('expiry_days'),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $side = fn (string $prefix, SideReward $reward): array => [
            $prefix.'_kind' => $reward->kind->value,
            $prefix.'_percent' => $reward->percent,
            $prefix.'_credit' => $reward->credit,
            $prefix.'_months' => $reward->months,
        ];

        return [
            'enabled' => $this->enabled,
            ...$side('referrer', $this->referrer),
            ...$side('referred', $this->referred),
            'qualifying_event' => $this->qualifyingEvent->value,
            'yearly_cap' => $this->yearlyCap,
            'credit_cap' => $this->creditCap,
            'expiry_days' => $this->expiryDays,
        ];
    }

    public function side(string $side): SideReward
    {
        return $side === 'referrer' ? $this->referrer : $this->referred;
    }
}
