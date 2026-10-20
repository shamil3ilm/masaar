<?php

declare(strict_types=1);

namespace App\Domains\Billing\Http\Controllers;

use App\Domains\Billing\Reconciliation\Parsers\CsvStatementParser;
use App\Domains\Billing\Reconciliation\Parsers\TextFile;
use App\Domains\Billing\Services\ExchangeRates;
use App\Domains\Billing\Services\PlatformInvoices;
use App\Domains\Billing\Settings\FinanceSettings;
use App\Domains\Platform\Http\Middleware\AuditAdminAction;
use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Admin › Finance › Settings: how the platform bills its customers.
 *
 * Every admin reads it; changing it takes billing.manage. Each change is a
 * customization at the platform's level, recorded in the audit log with
 * its value before and after (Customizations), and the console's own record
 * of the request besides (AuditAdminAction).
 */
class AdminFinanceSettingsController extends Controller
{
    public function __construct(
        private readonly FinanceSettings $settings,
        private readonly ExchangeRates $rates,
        private readonly PlatformInvoices $platform,
    ) {}

    public function index(): View
    {
        return view('admin.finance.settings', [
            'billable' => FinanceSettings::billable(),
            'enabled' => $this->settings->enabledCurrencies(),
            'default' => $this->settings->currencyFor(),
            'accounts' => $this->settings->bankAccounts(),
            'profiles' => $this->settings->statementProfiles(),
            'methods' => FinanceSettings::METHOD_DEFAULTS,
            'settings' => $this->settings,
            'rates' => $this->rates->recent(),
            'taxCurrency' => $this->platform->taxCurrency(),
        ]);
    }

    public function currencies(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'currencies' => ['array'],
            'currencies.*' => ['string', Rule::in(FinanceSettings::billable())],
            'default' => ['required', 'string', Rule::in(FinanceSettings::billable())],
        ]);

        $this->settings->setCurrencies($data['currencies'] ?? [], $data['default'], $this->actor($request));

        return $this->saved(__('Currencies saved.'));
    }

    public function methods(Request $request): RedirectResponse
    {
        $this->settings->setMethods([
            'bank_transfer' => $request->boolean('bank_transfer'),
            'card' => $request->boolean('card'),
        ], $this->actor($request));

        return $this->saved(__('Payment methods saved.'));
    }

    public function terms(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'free_months' => ['required', 'integer'],
            'payment_terms_days' => ['required', 'integer'],
            'reminder_days_before' => ['nullable', 'string', 'max:100'],
            'reminder_days_after' => ['nullable', 'string', 'max:100'],
        ]);
        $this->settings->setMany([
            FinanceSettings::FREE_MONTHS => $data['free_months'],
            FinanceSettings::PAYMENT_TERMS => $data['payment_terms_days'],
            FinanceSettings::REMINDERS_BEFORE => (string) ($data['reminder_days_before'] ?? ''),
            FinanceSettings::REMINDERS_AFTER => (string) ($data['reminder_days_after'] ?? ''),
        ], $this->actor($request));

        return $this->saved(__('Terms and reminders saved.'));
    }

    public function exchange(Request $request): RedirectResponse
    {
        $data = $request->validate(['source' => ['required', 'string', Rule::in([FinanceSettings::RATE_MANUAL, FinanceSettings::RATE_TABLE])]]);

        $this->settings->set(FinanceSettings::RATE_SOURCE, $data['source'], $this->actor($request));

        return $this->saved(__('Exchange rate source saved.'));
    }

    public function rate(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'currency' => ['required', 'string', Rule::in(FinanceSettings::billable())],
            'rate_date' => ['required', 'date'],
            'rate' => ['required', 'numeric', 'gt:0'],
        ]);

        $this->rates->record($data['currency'], CarbonImmutable::parse($data['rate_date']), (string) $data['rate'], $this->actor($request));

        return $this->saved(__('Rate saved.'));
    }

    public function reconciliation(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'tolerance' => ['required', 'numeric'],
            'window_days' => ['required', 'integer'],
            'fee_write_off' => ['required', 'numeric'],
        ]);
        $this->settings->setMany([
            FinanceSettings::TOLERANCE => (string) $data['tolerance'],
            FinanceSettings::WINDOW => $data['window_days'],
            FinanceSettings::FEE_WRITE_OFF => (string) $data['fee_write_off'],
        ], $this->actor($request));

        return $this->saved(__('Reconciliation limits saved.'));
    }

    public function addAccount(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'currency' => ['required', 'string', Rule::in(FinanceSettings::billable())],
            'bank_name' => ['required', 'string', 'max:120'],
            'account_name' => ['required', 'string', 'max:120'],
            'iban' => ['required', 'string', 'max:42'],
            'swift' => ['nullable', 'string', 'max:11'],
        ]);

        $this->settings->addBankAccount($data, $this->actor($request));

        return $this->saved(__('Bank account added.'));
    }

    public function removeAccount(Request $request, string $key): RedirectResponse
    {
        $this->settings->removeBankAccount($key, $this->actor($request));

        return $this->saved(__('Bank account removed.'));
    }

    /** How one bank's CSV statement is read (Reconciliation). */
    public function addProfile(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'bank_account' => ['required', 'string', 'max:40'],
            'delimiter' => ['required', 'string', Rule::in(array_keys(CsvStatementParser::DELIMITERS))],
            'encoding' => ['required', 'string', Rule::in(TextFile::ENCODINGS)],
            'date_format' => ['required', 'string', Rule::in(CsvStatementParser::DATE_FORMATS)],
            'decimal' => ['required', 'string', Rule::in(['.', ','])],
            'skip_rows' => ['nullable', 'integer', 'min:0', 'max:50'],
            ...array_fill_keys(CsvStatementParser::COLUMNS, ['nullable', 'string', 'max:60']),
        ]);

        $this->settings->addStatementProfile($data, $this->actor($request));

        return $this->saved(__('Statement profile added.'));
    }

    public function removeProfile(Request $request, string $key): RedirectResponse
    {
        $this->settings->removeStatementProfile($key, $this->actor($request));

        return $this->saved(__('Statement profile removed.'));
    }

    /** Who acts, with the change named for the console's audit record. */
    private function actor(Request $request): string
    {
        $request->attributes->set(AuditAdminAction::TARGET, ['Customization', null]);

        return (string) Auth::id();
    }

    private function saved(string $message): RedirectResponse
    {
        return redirect()->route('admin.finance.settings')->with('success', $message);
    }
}
