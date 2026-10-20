<?php

declare(strict_types=1);

namespace App\Domains\Billing\Services;

use App\Domains\Billing\Settings\FinanceSettings;
use App\Domains\Organization\Models\Organization;
use Carbon\CarbonImmutable;

/**
 * One customer's account with the platform at a glance, for its billing
 * page in the console and in the portal: its currency and terms, its free
 * time, its invoices, what it owes by age and what it has to its credit.
 */
class CustomerAccount
{
    public function __construct(
        private readonly FinanceSettings $settings,
        private readonly Receivables $receivables,
        private readonly Statements $statements,
        private readonly Credits $credits,
        private readonly FreePeriod $freePeriod,
        private readonly PlatformInvoices $platform,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function summary(Organization $customer): array
    {
        $id = (string) $customer->id;
        $currency = $this->settings->currencyFor($id);
        $receivables = $this->receivables->forCustomer($id);

        return [
            'currency' => $currency,
            'currency_enabled' => $this->settings->currencyEnabled($currency),
            'tax_currency' => $this->platform->taxCurrency(),
            'terms' => [
                FinanceSettings::CURRENCY => $currency,
                FinanceSettings::FREE_MONTHS => $this->settings->freeMonths($id),
                FinanceSettings::PAYMENT_TERMS => $this->settings->paymentTermsDays($id),
                FinanceSettings::REMINDERS_BEFORE => $this->settings->remindersBefore($id),
                FinanceSettings::REMINDERS_AFTER => $this->settings->remindersAfter($id),
            ],
            'own' => $this->settings->ownValues($id),
            'free_until' => $this->freePeriod->endsFor($customer),
            'receivables' => $receivables,
            'numbers' => $this->receivables->numbers($receivables),
            'platform_terms' => [
                FinanceSettings::CURRENCY => $this->settings->currencyFor(),
                FinanceSettings::FREE_MONTHS => $this->settings->freeMonths(),
                FinanceSettings::PAYMENT_TERMS => $this->settings->paymentTermsDays(),
                FinanceSettings::REMINDERS_BEFORE => $this->settings->remindersBefore(),
                FinanceSettings::REMINDERS_AFTER => $this->settings->remindersAfter(),
            ],
            'ageing' => $this->statements->ageing(CarbonImmutable::now(), $id),
            'credit' => $this->credits->balance($id, $currency),
            'accounts' => $this->settings->bankAccounts($currency),
            'rate_source' => $this->settings->rateSource(),
        ];
    }
}
