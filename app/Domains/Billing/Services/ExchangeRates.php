<?php

declare(strict_types=1);

namespace App\Domains\Billing\Services;

use App\Domains\Audit\Services\AuditService;
use App\Domains\Billing\Models\ExchangeRate;
use App\Domains\Billing\Settings\FinanceSettings;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

/**
 * The daily rate table: one rate per currency per day, to the tax currency
 * of the billing organization's country.
 *
 * An invoice in another currency records the rate it was issued at
 * (invoices.exchange_rate), and its VAT is stated at that rate in the tax
 * currency as well. Where the source is the table, the rate is the latest
 * one on or before the invoice's date, and the receivable keeps which day
 * it was.
 */
class ExchangeRates
{
    public function __construct(
        private readonly Billing $billing,
        private readonly AuditService $audit,
    ) {}

    /**
     * The table's rate for a currency on a day: that day's, or the latest
     * before it.
     *
     * @return array{rate: string, date: string}|null
     */
    public function on(string $currency, CarbonImmutable $date): ?array
    {
        // Half-open, so the day counts whether the column holds a date or a
        // date and midnight, as SQLite keeps it.
        $row = ExchangeRate::query()
            ->where('currency', strtoupper($currency))
            ->where('rate_date', '<', $date->addDay()->toDateString())
            ->orderByDesc('rate_date')
            ->first();

        return $row === null ? null : ['rate' => (string) $row->rate, 'date' => $row->rate_date->toDateString()];
    }

    /**
     * @return Collection<int, ExchangeRate>
     */
    public function recent(int $limit = 30): Collection
    {
        return ExchangeRate::query()->orderByDesc('rate_date')->orderBy('currency')->limit($limit)->get();
    }

    /**
     * A day's rate, entered or corrected. A correction changes what the
     * table says from now on, not the rate an invoice already recorded.
     *
     * @throws ValidationException
     */
    public function record(string $currency, CarbonImmutable $date, string $rate, ?string $actorId): ExchangeRate
    {
        $currency = strtoupper($currency);

        if (! in_array($currency, FinanceSettings::billable(), true) || $currency === $this->billing->taxCurrency()) {
            throw ValidationException::withMessages(['currency' => __('A rate is kept for a currency the platform bills in, other than the tax currency.')]);
        }

        if (! is_numeric($rate) || (float) $rate <= 0 || (float) $rate > 1000000) {
            throw ValidationException::withMessages(['rate' => __('The rate is a positive number.')]);
        }

        $row = ExchangeRate::query()->where('currency', $currency)->whereDate('rate_date', $date->toDateString())->first()
            ?? new ExchangeRate(['currency' => $currency, 'rate_date' => $date->toDateString()]);
        $before = $row->exists ? (string) $row->rate : null;
        $row->rate = number_format((float) $rate, 6, '.', '');
        $row->save();

        $this->audit->logChange('finance.exchange_rate.saved', 'ExchangeRate', (string) $row->id,
            ['rate' => $before], ['rate' => (string) $row->rate], ['currency' => $currency, 'date' => $date->toDateString()], $actorId);

        return $row;
    }
}
