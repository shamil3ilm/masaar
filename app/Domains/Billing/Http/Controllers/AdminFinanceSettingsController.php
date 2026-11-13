<?php

declare(strict_types=1);

namespace App\Domains\Billing\Http\Controllers;

use App\Domains\Billing\Http\Requests\AddNamedListSettingRequest;
use App\Domains\Billing\Http\Requests\StoreBankAccountRequest;
use App\Domains\Billing\Http\Requests\StoreExchangeRateRequest;
use App\Domains\Billing\Http\Requests\StoreStatementProfileRequest;
use App\Domains\Billing\Http\Requests\UpdateCurrenciesRequest;
use App\Domains\Billing\Http\Requests\UpdateFinanceTermsRequest;
use App\Domains\Billing\Http\Requests\UpdateMissingBillAlertsRequest;
use App\Domains\Billing\Http\Requests\UpdateRateSourceRequest;
use App\Domains\Billing\Http\Requests\UpdateReconciliationSettingsRequest;
use App\Domains\Billing\Http\Requests\UpdateVatSettingsRequest;
use App\Domains\Billing\Services\ExchangeRates;
use App\Domains\Billing\Services\PlatformInvoices;
use App\Domains\Billing\Settings\FinanceSettings;
use App\Domains\Platform\Http\Middleware\AuditAdminAction;
use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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
    /** The named lists the expenses are sorted by, as the URL names them. */
    public const LISTS = ['categories' => FinanceSettings::EXPENSE_CATEGORIES, 'cost-centres' => FinanceSettings::COST_CENTRES];

    public function __construct(
        private readonly FinanceSettings $settings,
        private readonly ExchangeRates $rates,
        private readonly PlatformInvoices $platform,
    ) {}

    public function index(): View
    {
        return view('admin.finance.settings', [
            'billable' => FinanceSettings::billable(),
            'rateCurrencies' => FinanceSettings::expenseCurrencies(),
            'enabled' => $this->settings->enabledCurrencies(),
            'default' => $this->settings->currencyFor(),
            'accounts' => $this->settings->bankAccounts(),
            'profiles' => $this->settings->statementProfiles(),
            'methods' => FinanceSettings::METHOD_DEFAULTS,
            'settings' => $this->settings,
            'rates' => $this->rates->recent(),
            'taxCurrency' => $this->platform->taxCurrency(),
            'lists' => [
                'categories' => ['title' => __('Expense categories'), 'entries' => $this->settings->expenseCategories()],
                'cost-centres' => ['title' => __('Cost centres'), 'entries' => $this->settings->costCentres()],
            ],
        ]);
    }

    public function currencies(UpdateCurrenciesRequest $request): RedirectResponse
    {
        $data = $request->validated();

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

    public function terms(UpdateFinanceTermsRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $this->settings->setMany([
            FinanceSettings::FREE_MONTHS => $data['free_months'],
            FinanceSettings::PAYMENT_TERMS => $data['payment_terms_days'],
            FinanceSettings::REMINDERS_BEFORE => (string) ($data['reminder_days_before'] ?? ''),
            FinanceSettings::REMINDERS_AFTER => (string) ($data['reminder_days_after'] ?? ''),
        ], $this->actor($request));

        return $this->saved(__('Terms and reminders saved.'));
    }

    public function exchange(UpdateRateSourceRequest $request): RedirectResponse
    {
        $data = $request->validated();

        $this->settings->set(FinanceSettings::RATE_SOURCE, $data['source'], $this->actor($request));

        return $this->saved(__('Exchange rate source saved.'));
    }

    public function rate(StoreExchangeRateRequest $request): RedirectResponse
    {
        $data = $request->validated();

        $this->rates->record($data['currency'], CarbonImmutable::parse($data['rate_date']), (string) $data['rate'], $this->actor($request));

        return $this->saved(__('Rate saved.'));
    }

    public function reconciliation(UpdateReconciliationSettingsRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $this->settings->setMany([
            FinanceSettings::TOLERANCE => (string) $data['tolerance'],
            FinanceSettings::WINDOW => $data['window_days'],
            FinanceSettings::FEE_WRITE_OFF => (string) $data['fee_write_off'],
        ], $this->actor($request));

        return $this->saved(__('Reconciliation limits saved.'));
    }

    public function addAccount(StoreBankAccountRequest $request): RedirectResponse
    {
        $data = $request->validated();

        $this->settings->addBankAccount($data, $this->actor($request));

        return $this->saved(__('Bank account added.'));
    }

    public function removeAccount(Request $request, string $key): RedirectResponse
    {
        $this->settings->removeBankAccount($key, $this->actor($request));

        return $this->saved(__('Bank account removed.'));
    }

    /** How one bank's CSV statement is read (Reconciliation). */
    public function addProfile(StoreStatementProfileRequest $request): RedirectResponse
    {
        $data = $request->validated();

        $this->settings->addStatementProfile($data, $this->actor($request));

        return $this->saved(__('Statement profile added.'));
    }

    public function removeProfile(Request $request, string $key): RedirectResponse
    {
        $this->settings->removeStatementProfile($key, $this->actor($request));

        return $this->saved(__('Statement profile removed.'));
    }

    /** Whether the platform is VAT registered, and how much input VAT it deducts. */
    public function purchaseVat(UpdateVatSettingsRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $this->settings->setMany([
            FinanceSettings::VAT_REGISTERED => $request->boolean('vat_registered'),
            FinanceSettings::INPUT_VAT_RECOVERY => $data['input_vat_recovery_percent'],
        ], $this->actor($request));

        return $this->saved(__('VAT on purchases saved.'));
    }

    /**
     * How long an expected bill may be late before it is missing, and from
     * how much a payment to a supplier is large.
     */
    public function expenses(UpdateMissingBillAlertsRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $this->settings->setMany([
            FinanceSettings::MISSING_BILL_DAYS => $data['missing_bill_days'],
            FinanceSettings::LARGE_PAYMENT => (string) $data['large_payment_threshold'],
            FinanceSettings::MISSING_BILL_EMAILS => $request->boolean('missing_bill_emails'),
            FinanceSettings::FEE_SOURCE => $data['fee_source'] ?? $this->settings->feeSource(),
        ], $this->actor($request));
        $this->settings->setFeeCategories(array_values($data['fee_categories'] ?? []), $this->actor($request));

        return $this->saved(__('Expenses settings saved.'));
    }

    /** An expense category or a cost centre, added to its list. */
    public function addNamed(AddNamedListSettingRequest $request, string $list): RedirectResponse
    {
        $data = $request->validated();
        $this->settings->addNamed(self::LISTS[$list], $data['name'], $this->actor($request));

        return $this->saved(__(':name added.', ['name' => trim($data['name'])]));
    }

    public function removeNamed(Request $request, string $list, string $key): RedirectResponse
    {
        $this->settings->removeNamed(self::LISTS[$list], $key, $this->actor($request));

        return $this->saved(__('Removed from the list.'));
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
