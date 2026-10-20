<?php

declare(strict_types=1);

namespace App\Domains\Billing\Settings;

use App\Domains\Billing\Enums\PaymentMethod;
use App\Domains\Platform\Customization\Customizations;
use App\Domains\Platform\Customization\Level;
use App\Domains\Platform\Customization\Scope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Admin › Finance › Settings, read and written: the currencies, bank
 * accounts, payment methods, terms, reminders, exchange rates and
 * reconciliation limits the platform bills its customers by.
 *
 * Every value lives in the customization store (FinanceCatalogue), so each
 * has its shipped default, the platform's, and - for what is agreed per
 * customer - the customer's own. What the store cannot check alone is
 * checked here: an IBAN, a default currency that is switched on.
 */
class FinanceSettings
{
    public const CURRENCIES = 'finance.currencies';

    public const CURRENCY = 'finance.currency';

    public const BANK_ACCOUNTS = 'finance.bank_accounts';

    public const PAYMENT_METHODS = 'finance.payment_methods';

    public const FREE_MONTHS = 'finance.free_months';

    public const PAYMENT_TERMS = 'finance.payment_terms_days';

    public const REMINDERS_BEFORE = 'finance.reminder_days_before';

    public const REMINDERS_AFTER = 'finance.reminder_days_after';

    public const RATE_SOURCE = 'finance.exchange_rate_source';

    public const TOLERANCE = 'finance.reconciliation_tolerance';

    public const WINDOW = 'finance.reconciliation_window_days';

    public const FREEZE_ENABLED = 'finance.freeze_enabled';

    public const FREEZE_POLICY = 'finance.freeze_policy';

    /** Two weeks of reminders, a week's grace, two weeks read-only, then frozen. */
    public const FREEZE_DEFAULTS = ['reminder_days' => 14, 'grace_days' => 7, 'restricted_days' => 14, 'exempt' => false];

    public const RATE_MANUAL = 'manual';

    public const RATE_TABLE = 'table';

    /** At launch only the first currency is on; the second is ready, and off. */
    public const CURRENCY_DEFAULTS = ['SAR' => true, 'AED' => false];

    public const METHOD_DEFAULTS = ['bank_transfer' => true, 'card' => true];

    /** What a customer's own value may be set for, on its billing page. */
    public const PER_CUSTOMER = [self::CURRENCY, self::FREE_MONTHS, self::PAYMENT_TERMS, self::REMINDERS_BEFORE, self::REMINDERS_AFTER];

    public function __construct(
        private readonly Customizations $customizations,
    ) {}

    /**
     * Every currency the platform can bill in, switched on or not.
     *
     * @return list<string>
     */
    public static function billable(): array
    {
        return array_values(array_map('strval', (array) config('billing.currencies', array_keys(self::CURRENCY_DEFAULTS))));
    }

    /**
     * @return list<string>
     */
    public function enabledCurrencies(): array
    {
        $switches = (array) $this->customizations->get(self::CURRENCIES, Scope::platform());

        return array_values(array_filter(self::billable(), fn (string $currency): bool => (bool) ($switches[$currency] ?? false)));
    }

    public function currencyEnabled(string $currency): bool
    {
        return in_array(strtoupper($currency), $this->enabledCurrencies(), true);
    }

    /** The platform's default currency, or the customer's own when it has one. */
    public function currencyFor(?string $organizationId = null): string
    {
        return (string) $this->customizations->get(self::CURRENCY, $this->scope($organizationId));
    }

    /**
     * The accounts customers pay into, those of one currency when it is
     * named, each with its key.
     *
     * @return list<array{key: string, currency: string, bank_name: string, account_name: string, iban: string, swift: string}>
     */
    public function bankAccounts(?string $currency = null): array
    {
        $accounts = [];

        foreach ((array) $this->customizations->get(self::BANK_ACCOUNTS, Scope::platform()) as $entry) {
            $account = [
                'key' => (string) ($entry['key'] ?? ''),
                'currency' => (string) ($entry['currency'] ?? ''),
                'bank_name' => (string) ($entry['bank_name'] ?? ''),
                'account_name' => (string) ($entry['account_name'] ?? ''),
                'iban' => (string) ($entry['iban'] ?? ''),
                'swift' => (string) ($entry['swift'] ?? ''),
            ];

            if ($currency === null || $account['currency'] === strtoupper($currency)) {
                $accounts[] = $account;
            }
        }

        return $accounts;
    }

    public function methodEnabled(PaymentMethod $method): bool
    {
        return (bool) (((array) $this->customizations->get(self::PAYMENT_METHODS, Scope::platform()))[$method->value] ?? false);
    }

    public function freeMonths(?string $organizationId = null): int
    {
        return (int) $this->customizations->get(self::FREE_MONTHS, $this->scope($organizationId));
    }

    public function paymentTermsDays(?string $organizationId = null): int
    {
        return (int) $this->customizations->get(self::PAYMENT_TERMS, $this->scope($organizationId));
    }

    /** @return list<int> */
    public function remindersBefore(?string $organizationId = null): array
    {
        return array_map('intval', (array) $this->customizations->get(self::REMINDERS_BEFORE, $this->scope($organizationId)));
    }

    /** @return list<int> */
    public function remindersAfter(?string $organizationId = null): array
    {
        return array_map('intval', (array) $this->customizations->get(self::REMINDERS_AFTER, $this->scope($organizationId)));
    }

    public function rateSource(): string
    {
        return (string) $this->customizations->get(self::RATE_SOURCE, Scope::platform());
    }

    /** The amount a statement line may differ by and still match, as "1.00". */
    public function tolerance(): string
    {
        return (string) $this->customizations->get(self::TOLERANCE, Scope::platform());
    }

    public function windowDays(): int
    {
        return (int) $this->customizations->get(self::WINDOW, Scope::platform());
    }

    public function freezeEnabled(): bool
    {
        return (bool) $this->customizations->get(self::FREEZE_ENABLED, Scope::platform());
    }

    /**
     * The overdue-payment stages for the platform, or for one customer:
     * its own days over the platform's, field by field.
     *
     * @return array{reminder_days: int, grace_days: int, restricted_days: int, exempt: bool}
     */
    public function freezePolicy(?string $organizationId = null): array
    {
        $policy = (array) $this->customizations->get(self::FREEZE_POLICY, $this->scope($organizationId));

        return [
            'reminder_days' => (int) ($policy['reminder_days'] ?? self::FREEZE_DEFAULTS['reminder_days']),
            'grace_days' => (int) ($policy['grace_days'] ?? self::FREEZE_DEFAULTS['grace_days']),
            'restricted_days' => (int) ($policy['restricted_days'] ?? self::FREEZE_DEFAULTS['restricted_days']),
            'exempt' => (bool) ($policy['exempt'] ?? false),
        ];
    }

    /**
     * The freeze's switch and the platform's stages, from one form: both or
     * neither.
     *
     * @param  array<string, mixed>  $policy
     *
     * @throws ValidationException
     */
    public function setFreeze(bool $enabled, array $policy, ?string $actorId): void
    {
        DB::transaction(function () use ($enabled, $policy, $actorId): void {
            $this->customizations->set(self::FREEZE_ENABLED, Level::Platform, Scope::platform(), $enabled, $actorId);
            $this->setFreezePolicy($policy, $actorId);
        });
    }

    /** What one customer sets of its own overdue-payment stages. */
    public function ownFreezePolicy(string $organizationId): array
    {
        return (array) ($this->customizations->own(self::FREEZE_POLICY, Level::Organization, Scope::organization($organizationId)) ?? []);
    }

    /**
     * The stages' days, for the platform or one customer. A customer's own
     * keeps only what it sets; null for a field follows the platform.
     *
     * @param  array<string, mixed>  $policy
     *
     * @throws ValidationException
     */
    public function setFreezePolicy(array $policy, ?string $actorId, ?string $organizationId = null): void
    {
        $value = [];

        foreach (['reminder_days', 'grace_days', 'restricted_days'] as $field) {
            if (! array_key_exists($field, $policy) || $policy[$field] === null || $policy[$field] === '') {
                continue;
            }

            if (filter_var($policy[$field], FILTER_VALIDATE_INT) === false || (int) $policy[$field] < 0 || (int) $policy[$field] > 365) {
                throw ValidationException::withMessages([$field => __('Each stage is a whole number of days, from 0 to 365.')]);
            }

            $value[$field] = (int) $policy[$field];
        }

        if (array_key_exists('exempt', $policy) && $policy['exempt'] !== null) {
            $value['exempt'] = (bool) $policy['exempt'];
        }

        $level = $organizationId === null ? Level::Platform : Level::Organization;

        if ($value === [] && $organizationId !== null) {
            $this->customizations->reset(self::FREEZE_POLICY, $level, $this->scope($organizationId), $actorId);

            return;
        }

        $this->customizations->set(self::FREEZE_POLICY, $level, $this->scope($organizationId), $value, $actorId);
    }

    /**
     * Which currencies are on, and the default: at least one, and the
     * default among them.
     *
     * @param  list<string>  $enabled
     *
     * @throws ValidationException
     */
    public function setCurrencies(array $enabled, string $default, ?string $actorId): void
    {
        $enabled = array_values(array_intersect(self::billable(), array_map('strtoupper', $enabled)));
        $default = strtoupper($default);

        if ($enabled === []) {
            throw ValidationException::withMessages(['currencies' => __('At least one currency must stay on.')]);
        }

        if (! in_array($default, $enabled, true)) {
            throw ValidationException::withMessages(['default' => __('The default currency must be one that is on.')]);
        }

        $this->customizations->set(self::CURRENCIES, Level::Platform, Scope::platform(),
            array_combine(self::billable(), array_map(fn (string $currency): bool => in_array($currency, $enabled, true), self::billable())), $actorId);
        $this->customizations->set(self::CURRENCY, Level::Platform, Scope::platform(), $default, $actorId);
    }

    /**
     * @param  array<string, bool>  $methods  method => on
     *
     * @throws ValidationException
     */
    public function setMethods(array $methods, ?string $actorId): void
    {
        $value = [];

        foreach (array_keys(self::METHOD_DEFAULTS) as $method) {
            $value[$method] = (bool) ($methods[$method] ?? false);
        }

        if (! in_array(true, $value, true)) {
            throw ValidationException::withMessages(['methods' => __('At least one payment method must stay on.')]);
        }

        $this->customizations->set(self::PAYMENT_METHODS, Level::Platform, Scope::platform(), $value, $actorId);
    }

    /**
     * Add an account customers pay into. The IBAN is checked and kept as
     * typed without spaces; the SWIFT code (BIC) is 8 or 11 characters.
     *
     * @param  array{currency: string, bank_name: string, account_name: string, iban: string, swift?: string|null}  $account
     *
     * @throws ValidationException
     */
    public function addBankAccount(array $account, ?string $actorId): void
    {
        $iban = Iban::normalise($account['iban']);
        $swift = strtoupper(trim((string) ($account['swift'] ?? '')));

        if (! in_array(strtoupper($account['currency']), self::billable(), true)) {
            throw ValidationException::withMessages(['currency' => __('The platform does not bill in that currency.')]);
        }

        if (! Iban::valid($iban)) {
            throw ValidationException::withMessages(['iban' => __('That is not a valid IBAN. Check its length and digits.')]);
        }

        if ($swift !== '' && preg_match('/^[A-Z]{6}[A-Z0-9]{2}([A-Z0-9]{3})?$/', $swift) !== 1) {
            throw ValidationException::withMessages(['swift' => __('A SWIFT code (BIC) is 8 or 11 letters and digits.')]);
        }

        if (collect($this->bankAccounts())->contains(fn (array $existing): bool => $existing['iban'] === $iban)) {
            throw ValidationException::withMessages(['iban' => __('That account is already listed.')]);
        }

        $this->customizations->set(self::BANK_ACCOUNTS, Level::Platform, Scope::platform(), [...$this->bankAccounts(), [
            'key' => (string) Str::uuid(),
            'currency' => strtoupper($account['currency']),
            'bank_name' => trim($account['bank_name']),
            'account_name' => trim($account['account_name']),
            'iban' => $iban,
            'swift' => $swift,
        ]], $actorId);
    }

    public function removeBankAccount(string $key, ?string $actorId): void
    {
        $this->customizations->set(self::BANK_ACCOUNTS, Level::Platform, Scope::platform(),
            array_values(array_filter($this->bankAccounts(), fn (array $account): bool => $account['key'] !== $key)), $actorId);
    }

    /**
     * One plain value - a term, a count of days, a source - for the
     * platform, or for one customer when it is named.
     *
     * @throws ValidationException
     */
    public function set(string $key, mixed $value, ?string $actorId, ?string $organizationId = null): void
    {
        if ($key === self::CURRENCY && is_scalar($value) && ! $this->currencyEnabled((string) $value)) {
            throw ValidationException::withMessages(['value' => __('That currency is switched off in Finance › Settings.')]);
        }

        $level = $organizationId === null ? Level::Platform : Level::Organization;

        $this->customizations->set($key, $level, $this->scope($organizationId), $value, $actorId);
    }

    /**
     * Several values from one form, all or none: one refused leaves the
     * others as they were.
     *
     * @param  array<string, mixed>  $values  key => value
     *
     * @throws ValidationException
     */
    public function setMany(array $values, ?string $actorId, ?string $organizationId = null): void
    {
        DB::transaction(function () use ($values, $actorId, $organizationId): void {
            foreach ($values as $key => $value) {
                $this->set($key, $value, $actorId, $organizationId);
            }
        });
    }

    /**
     * A customer's own terms from its billing page, all or none: a value
     * given is its own, an empty one follows the platform's again.
     *
     * @param  array<string, mixed>  $values  key => value, null or '' to follow the platform
     *
     * @throws ValidationException
     */
    public function setForCustomer(string $organizationId, array $values, ?string $actorId): void
    {
        DB::transaction(function () use ($organizationId, $values, $actorId): void {
            foreach ($values as $key => $value) {
                if (! in_array($key, self::PER_CUSTOMER, true)) {
                    throw ValidationException::withMessages([$key => __('That is not set per customer.')]);
                }

                $value === null || $value === ''
                    ? $this->reset($key, $organizationId, $actorId)
                    : $this->set($key, $value, $actorId, $organizationId);
            }
        });
    }

    /** A customer's own value gone: it follows the platform's again. */
    public function reset(string $key, string $organizationId, ?string $actorId): void
    {
        $this->customizations->reset($key, Level::Organization, Scope::organization($organizationId), $actorId);
    }

    /** What one customer has of its own, by key: null where it follows the platform. */
    public function ownValues(string $organizationId): array
    {
        $own = [];

        foreach (self::PER_CUSTOMER as $key) {
            $own[$key] = $this->customizations->own($key, Level::Organization, Scope::organization($organizationId));
        }

        return $own;
    }

    private function scope(?string $organizationId): Scope
    {
        return $organizationId === null ? Scope::platform() : Scope::organization($organizationId);
    }
}
