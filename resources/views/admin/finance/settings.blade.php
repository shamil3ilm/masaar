@extends('layouts.admin')

@section('title', __('Finance settings'))

@section('content')
<x-ui.page-header :title="__('Finance settings')" :description="__('How the platform bills its own customers. No price is set here: each customer\'s amount is agreed with them and entered when they are invoiced.')" />

@php($canChange = auth()->user()?->canAdmin('billing.manage'))
@unless ($canChange)
    <x-ui.alert tone="info" class="mb-6">{{ __('You can read these settings. Changing them takes the billing permission.') }}</x-ui.alert>
@endunless

<div class="max-w-4xl space-y-6">
    <x-ui.card :title="__('Currencies')" :description="__('A customer is invoiced and pays in its own currency; its VAT is also stated in :currency at the invoice\'s exchange rate.', ['currency' => $taxCurrency])">
        <form method="POST" action="{{ route('admin.finance.settings.currencies') }}" class="space-y-4">
            @csrf
            <div class="flex flex-wrap gap-x-6">
                @foreach ($billable as $currency)
                    <x-ui.checkbox name="currencies[]" value="{{ $currency }}" :checked="in_array($currency, old('currencies', $enabled), true)" :disabled="! $canChange">
                        {{ $currency }}
                    </x-ui.checkbox>
                @endforeach
            </div>
            <x-ui.field :label="__('Default currency')" error="default" class="max-w-xs">
                <x-ui.select name="default" :disabled="! $canChange">
                    @foreach ($billable as $currency)
                        <option value="{{ $currency }}" @selected(old('default', $default) === $currency)>{{ $currency }}</option>
                    @endforeach
                </x-ui.select>
            </x-ui.field>
            @if ($canChange)
                <x-ui.button>{{ __('Save currencies') }}</x-ui.button>
            @endif
        </form>
    </x-ui.card>

    <x-ui.card :title="__('Bank accounts')" :description="__('Where customers pay by transfer, per currency. Invoices, statements and reminders name the accounts of their currency.')" flush>
        <x-ui.table :primary="[0, 2]">
            <x-slot:head>
                <x-ui.th>{{ __('Bank') }}</x-ui.th>
                <x-ui.th>{{ __('Account name') }}</x-ui.th>
                <x-ui.th>{{ __('IBAN') }}</x-ui.th>
                <x-ui.th>{{ __('Currency') }}</x-ui.th>
                <x-ui.th align="right">{{ __('Actions') }}</x-ui.th>
            </x-slot:head>
            @forelse ($accounts as $account)
                <tr class="hover:bg-sunken">
                    <td class="px-6 py-4 text-sm font-medium text-fg">{{ $account['bank_name'] }}
                        @if ($account['swift'] !== '')
                            <span class="block text-xs text-muted" dir="ltr">{{ $account['swift'] }}</span>
                        @endif
                    </td>
                    <td class="px-6 py-4 text-sm text-muted">{{ $account['account_name'] }}</td>
                    <td class="px-6 py-4 text-sm font-mono text-fg" dir="ltr">{{ \App\Domains\Billing\Settings\Iban::format($account['iban']) }}</td>
                    <td class="px-6 py-4 text-sm text-muted">{{ $account['currency'] }}</td>
                    <td class="px-6 py-4 text-right text-sm">
                        @if ($canChange)
                            <form method="POST" action="{{ route('admin.finance.settings.bank-accounts.remove', $account['key']) }}"
                                  data-confirm="{{ __('Remove the account at :bank?', ['bank' => $account['bank_name']]) }}"
                                  data-confirm-detail="{{ __('Invoices and statements stop naming it. Invoices already issued keep what they printed. You can add it again.') }}"
                                  data-confirm-action="{{ __('Remove') }}" data-confirm-tone="danger">
                                @csrf
                                <x-ui.button variant="ghost" size="sm">{{ __('Remove') }}</x-ui.button>
                            </form>
                        @endif
                    </td>
                </tr>
            @empty
                <x-ui.empty :columns="5">{{ __('No bank account yet. Customers paying by transfer need one in their currency.') }}</x-ui.empty>
            @endforelse
        </x-ui.table>
        @if ($canChange)
            <form method="POST" action="{{ route('admin.finance.settings.bank-accounts.store') }}" class="grid grid-cols-1 gap-4 border-t border-line p-5 sm:grid-cols-2">
                @csrf
                <x-ui.field :label="__('Currency')" error="currency">
                    <x-ui.select name="currency">
                        @foreach ($billable as $currency)
                            <option value="{{ $currency }}" @selected(old('currency') === $currency)>{{ $currency }}</option>
                        @endforeach
                    </x-ui.select>
                </x-ui.field>
                <x-ui.field :label="__('Bank')" error="bank_name">
                    <x-ui.input name="bank_name" maxlength="120" required value="{{ old('bank_name') }}" />
                </x-ui.field>
                <x-ui.field :label="__('Account name')" error="account_name">
                    <x-ui.input name="account_name" maxlength="120" required value="{{ old('account_name') }}" />
                </x-ui.field>
                <x-ui.field :label="__('IBAN')" error="iban">
                    <x-ui.input name="iban" maxlength="42" required dir="ltr" class="font-mono uppercase" value="{{ old('iban') }}" />
                </x-ui.field>
                <x-ui.field :label="__('SWIFT code (BIC)')" error="swift" optional>
                    <x-ui.input name="swift" maxlength="11" dir="ltr" class="font-mono uppercase" value="{{ old('swift') }}" />
                </x-ui.field>
                <div class="flex items-end">
                    <x-ui.button variant="secondary">{{ __('Add account') }}</x-ui.button>
                </div>
            </form>
        @endif
    </x-ui.card>

    {{-- What the platform's own expenses are sorted by. Taking a name off
         the list leaves the expenses that have it as they are. --}}
    @foreach ($lists as $list => $named)
        <x-ui.card :title="$named['title']" :description="__('Expenses and their reports are sorted by these. Taking one off leaves the expenses that have it as they are.')" flush>
            <x-ui.table :primary="[0]">
                <x-slot:head>
                    <x-ui.th>{{ __('Name') }}</x-ui.th>
                    <x-ui.th align="right">{{ __('Actions') }}</x-ui.th>
                </x-slot:head>
                @forelse ($named['entries'] as $key => $name)
                    <tr class="hover:bg-sunken">
                        <td class="px-6 py-3 text-sm text-fg">{{ $name }}</td>
                        <td class="px-6 py-3 text-right text-sm">
                            @if ($canChange)
                                <form method="POST" action="{{ route('admin.finance.settings.lists.remove', [$list, $key]) }}"
                                      data-confirm="{{ __('Take :name off the list?', ['name' => $name]) }}"
                                      data-confirm-detail="{{ __('New expenses cannot choose it. Expenses that have it keep it. You can add it again.') }}"
                                      data-confirm-action="{{ __('Take off') }}" data-confirm-tone="danger">
                                    @csrf
                                    <x-ui.button variant="ghost" size="sm">{{ __('Take off') }}</x-ui.button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @empty
                    <x-ui.empty :columns="2">{{ __('The list is empty.') }}</x-ui.empty>
                @endforelse
            </x-ui.table>
            @if ($canChange)
                <form method="POST" action="{{ route('admin.finance.settings.lists.store', $list) }}" class="flex flex-wrap items-end gap-4 border-t border-line p-5">
                    @csrf
                    <x-ui.field :label="__('Name')" error="name">
                        <x-ui.input name="name" maxlength="80" required />
                    </x-ui.field>
                    <x-ui.button variant="secondary">{{ __('Add') }}</x-ui.button>
                </form>
            @endif
        </x-ui.card>
    @endforeach

    <x-ui.card :title="__('Payment methods')" :description="__('A transfer is recorded by an admin when it arrives. A card payment goes through the card gateway.')">
        <form method="POST" action="{{ route('admin.finance.settings.methods') }}" class="space-y-2">
            @csrf
            <x-ui.switch name="bank_transfer" value="1" off="0" :checked="$settings->methodEnabled(\App\Domains\Billing\Enums\PaymentMethod::BankTransfer)" :disabled="! $canChange">{{ __('Bank transfer') }}</x-ui.switch>
            <x-ui.switch name="card" value="1" off="0" :checked="$settings->methodEnabled(\App\Domains\Billing\Enums\PaymentMethod::Card)" :disabled="! $canChange">{{ __('Card') }}</x-ui.switch>
            @if ($canChange)
                <div><x-ui.button>{{ __('Save payment methods') }}</x-ui.button></div>
            @endif
        </form>
    </x-ui.card>

    <x-ui.card :title="__('Terms and reminders')" :description="__('The platform\'s defaults. A customer\'s billing page can set its own.')">
        <form method="POST" action="{{ route('admin.finance.settings.terms') }}" class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            @csrf
            <x-ui.field :label="__('Free months from approval')" error="finance.free_months" :hint="__('Counted from each customer\'s approval. Nothing is invoiced for it.')">
                <x-ui.input type="number" name="free_months" min="0" max="24" required value="{{ old('free_months', $settings->freeMonths()) }}" :disabled="! $canChange" />
            </x-ui.field>
            <x-ui.field :label="__('Payment terms (days)')" error="finance.payment_terms_days" :hint="__('Days from an invoice\'s date to when it is due.')">
                <x-ui.input type="number" name="payment_terms_days" min="0" max="180" required value="{{ old('payment_terms_days', $settings->paymentTermsDays()) }}" :disabled="! $canChange" />
            </x-ui.field>
            <x-ui.field :label="__('Reminder days before the due date')" error="finance.reminder_days_before" :hint="__('A list, such as 7, 1. Empty for none.')">
                <x-ui.input name="reminder_days_before" maxlength="100" value="{{ old('reminder_days_before', implode(', ', $settings->remindersBefore())) }}" :disabled="! $canChange" />
            </x-ui.field>
            <x-ui.field :label="__('Reminder days after the due date')" error="finance.reminder_days_after" :hint="__('While it is unpaid, such as 1, 7, 14. Empty for none.')">
                <x-ui.input name="reminder_days_after" maxlength="100" value="{{ old('reminder_days_after', implode(', ', $settings->remindersAfter())) }}" :disabled="! $canChange" />
            </x-ui.field>
            @if ($canChange)
                <div class="sm:col-span-2"><x-ui.button>{{ __('Save terms and reminders') }}</x-ui.button></div>
            @endif
        </form>
    </x-ui.card>

    <x-ui.card :title="__('Exchange rates')" :description="__('An invoice in another currency states its VAT in :currency at the rate it records. Expenses billed in another currency take the rate on their date from this table unless one is entered.', ['currency' => $taxCurrency])">
        <form method="POST" action="{{ route('admin.finance.settings.exchange') }}" class="flex flex-wrap items-end gap-4">
            @csrf
            <x-ui.field :label="__('Exchange rate source')" error="source" class="min-w-64">
                <x-ui.select name="source" :disabled="! $canChange">
                    <option value="{{ \App\Domains\Billing\Settings\FinanceSettings::RATE_MANUAL }}" @selected($settings->rateSource() === \App\Domains\Billing\Settings\FinanceSettings::RATE_MANUAL)>{{ __('Entered for each invoice') }}</option>
                    <option value="{{ \App\Domains\Billing\Settings\FinanceSettings::RATE_TABLE }}" @selected($settings->rateSource() === \App\Domains\Billing\Settings\FinanceSettings::RATE_TABLE)>{{ __('The daily rate table') }}</option>
                </x-ui.select>
            </x-ui.field>
            @if ($canChange)
                <x-ui.button variant="secondary">{{ __('Save source') }}</x-ui.button>
            @endif
        </form>

        <div class="-mx-5 mt-5 border-t border-line">
            <x-ui.table :primary="[0, 1, 2]">
                <x-slot:head>
                    <x-ui.th>{{ __('Date') }}</x-ui.th>
                    <x-ui.th>{{ __('Currency') }}</x-ui.th>
                    <x-ui.th align="right">{{ __('Rate') }}</x-ui.th>
                </x-slot:head>
                @forelse ($rates as $rate)
                    <tr class="hover:bg-sunken">
                        <td class="px-6 py-3 text-sm text-fg">{{ $rate->rate_date->toDateString() }}</td>
                        <td class="px-6 py-3 text-sm text-muted">{{ $rate->currency }}</td>
                        <td class="px-6 py-3 text-right text-sm tabular-nums text-fg" dir="ltr">1 {{ $rate->currency }} = {{ rtrim(rtrim((string) $rate->rate, '0'), '.') }} {{ $taxCurrency }}</td>
                    </tr>
                @empty
                    <x-ui.empty :columns="3">{{ __('No rate in the table yet.') }}</x-ui.empty>
                @endforelse
            </x-ui.table>
        </div>

        @if ($canChange)
            <form method="POST" action="{{ route('admin.finance.settings.rates.store') }}" class="mt-5 grid grid-cols-1 gap-4 sm:grid-cols-4">
                @csrf
                <x-ui.field :label="__('Currency')" error="currency">
                    <x-ui.select name="currency">
                        @foreach (array_diff($rateCurrencies, [$taxCurrency]) as $currency)
                            <option value="{{ $currency }}">{{ $currency }}</option>
                        @endforeach
                    </x-ui.select>
                </x-ui.field>
                <x-ui.field :label="__('Date')" error="rate_date">
                    <x-ui.date-input name="rate_date" required value="{{ old('rate_date', now()->toDateString()) }}" />
                </x-ui.field>
                <x-ui.field :label="__('Rate to :currency', ['currency' => $taxCurrency])" error="rate">
                    <x-ui.input type="number" name="rate" step="0.000001" min="0.000001" required dir="ltr" value="{{ old('rate') }}" />
                </x-ui.field>
                <div class="flex items-end"><x-ui.button variant="secondary">{{ __('Save rate') }}</x-ui.button></div>
            </form>
        @endif
    </x-ui.card>

    @php($freeze = $settings->freezePolicy())
    <x-ui.card :title="__('Overdue-payment freeze')" :description="__('Off at launch. On, a customer whose oldest unpaid invoice is past due is reminded, then given its grace, then restricted to reading - nothing new issued, nobody new added - then frozen. It never stops a customer reading and exporting its records, or the filing of what it already issued.')">
        <form method="POST" action="{{ route('admin.finance.settings.freeze') }}" class="space-y-4">
            @csrf
            <x-ui.switch name="enabled" value="1" off="0" :checked="$settings->freezeEnabled()" :disabled="! $canChange">{{ __('Freeze accounts with overdue payments') }}</x-ui.switch>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                <x-ui.field :label="__('Reminder days')" error="reminder_days">
                    <x-ui.input type="number" name="reminder_days" min="0" max="365" required value="{{ old('reminder_days', $freeze['reminder_days']) }}" :disabled="! $canChange" />
                </x-ui.field>
                <x-ui.field :label="__('Grace days')" error="grace_days">
                    <x-ui.input type="number" name="grace_days" min="0" max="365" required value="{{ old('grace_days', $freeze['grace_days']) }}" :disabled="! $canChange" />
                </x-ui.field>
                <x-ui.field :label="__('Read-only days')" error="restricted_days">
                    <x-ui.input type="number" name="restricted_days" min="0" max="365" required value="{{ old('restricted_days', $freeze['restricted_days']) }}" :disabled="! $canChange" />
                </x-ui.field>
            </div>
            @if ($canChange)
                <x-ui.button>{{ __('Save the freeze') }}</x-ui.button>
            @endif
        </form>
    </x-ui.card>

    <x-ui.card :title="__('Reconciliation')" :description="__('How closely a bank statement line must match a payment to be suggested as it.')">
        <form method="POST" action="{{ route('admin.finance.settings.reconciliation') }}" class="grid grid-cols-1 gap-4 sm:grid-cols-3">
            @csrf
            <x-ui.field :label="__('Amount tolerance')" error="finance.reconciliation_tolerance">
                <x-ui.input type="number" name="tolerance" step="0.01" min="0" max="10000" required dir="ltr" value="{{ old('tolerance', $settings->tolerance()) }}" :disabled="! $canChange" />
            </x-ui.field>
            <x-ui.field :label="__('Date window (days)')" error="finance.reconciliation_window_days">
                <x-ui.input type="number" name="window_days" min="0" max="90" required value="{{ old('window_days', $settings->windowDays()) }}" :disabled="! $canChange" />
            </x-ui.field>
            @if ($canChange)
                <div class="flex items-end"><x-ui.button>{{ __('Save limits') }}</x-ui.button></div>
            @endif
        </form>
    </x-ui.card>
</div>
@endsection
