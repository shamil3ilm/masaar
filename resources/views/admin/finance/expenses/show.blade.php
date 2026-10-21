@extends('layouts.admin')

@php
    $title = $expense->supplier?->name.' · '.$expense->expense_date->toDateString();
    $canManage = (bool) auth()->user()?->canAdmin('billing.manage');
    $rate = fn ($value) => rtrim(rtrim((string) $value, '0'), '.');
@endphp

@section('title', $title)

@section('content')
{{--
    One expense: the bill, its VAT as the return takes it, its receipt -
    an image shown here, a PDF opened on its own - and what can still be
    done with it.
--}}
<x-ui.page-header :eyebrow="__('Expense')" :title="$title" :description="$expense->description">
    <x-slot:breadcrumb>
        <x-ui.breadcrumb :items="[
            ['label' => __('Expenses'), 'href' => route('admin.finance.expenses')],
            ['label' => $title],
        ]" />
    </x-slot:breadcrumb>
    @if ($canManage && $expense->editable())
        <x-slot:actions>
            <x-ui.button variant="secondary" :href="route('admin.finance.expenses.edit', $expense->id)">{{ __('Change') }}</x-ui.button>
        </x-slot:actions>
    @endif
</x-ui.page-header>

@if ($expense->status === \App\Domains\Billing\Enums\ExpenseStatus::Void)
    <x-ui.alert tone="warning" class="mb-6">{{ __('Void on :date: :reason', ['date' => $expense->voided_at?->toDateString(), 'reason' => $expense->void_reason]) }}</x-ui.alert>
@elseif ($expense->status === \App\Domains\Billing\Enums\ExpenseStatus::Draft)
    <x-ui.alert tone="info" class="mb-6">{{ __('A draft: counted nowhere until it is recorded. Check it against the bill, change what differs, then record it.') }}</x-ui.alert>
@endif

<div class="grid gap-6 lg:grid-cols-3">
    <div class="space-y-6 lg:col-span-2">
        <x-ui.card :title="__('The bill')">
            <dl class="grid grid-cols-1 gap-x-8 gap-y-2 text-sm sm:grid-cols-2">
                <div class="flex justify-between gap-4"><dt class="text-muted">{{ __('Status') }}</dt><dd><x-ui.badge :state="$expense->status->state()" :label="$expense->status->label()" /></dd></div>
                <div class="flex justify-between gap-4"><dt class="text-muted">{{ __('Supplier') }}</dt><dd>
                    @if ($expense->supplier)
                        <a href="{{ route('admin.finance.suppliers.show', $expense->supplier_id) }}" class="text-accent hover:underline">{{ $expense->supplier->name }}</a>
                    @endif
                </dd></div>
                <div class="flex justify-between gap-4"><dt class="text-muted">{{ __('Supplier invoice') }}</dt><dd dir="ltr">{{ $expense->supplier_invoice_number ?? '-' }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="text-muted">{{ __('Due') }}</dt><dd>{{ $expense->due_date->toDateString() }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="text-muted">{{ __('Category') }}</dt><dd>{{ $categories[$expense->category] ?? $expense->category }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="text-muted">{{ __('Cost centre') }}</dt><dd>{{ $centres[(string) $expense->cost_centre] ?? ($expense->cost_centre ?? '-') }}</dd></div>
            </dl>
            @if ($expense->notes)
                <p class="mt-4 whitespace-pre-line text-sm text-muted">{{ $expense->notes }}</p>
            @endif
        </x-ui.card>

        <x-ui.card :title="__('Amounts and VAT')" :description="$expense->vat_treatment->label()">
            <dl class="grid grid-cols-1 gap-x-8 gap-y-2 text-sm sm:grid-cols-2">
                <div class="flex justify-between gap-4"><dt class="text-muted">{{ __('Before VAT') }}</dt><dd><x-ui.amount :value="$expense->net" :currency="$expense->currency" /></dd></div>
                <div class="flex justify-between gap-4"><dt class="text-muted">{{ __('VAT charged') }}</dt><dd><x-ui.amount :value="$expense->vat" :currency="$expense->currency" /></dd></div>
                <div class="flex justify-between gap-4"><dt class="text-muted">{{ __('Total') }}</dt><dd><x-ui.amount :value="$expense->gross" :currency="$expense->currency" /></dd></div>
                @if ($expense->exchange_rate !== null)
                    <div class="flex justify-between gap-4"><dt class="text-muted">{{ __('Exchange rate') }}</dt><dd dir="ltr">1 {{ $expense->currency }} = {{ $rate($expense->exchange_rate) }} {{ $expense->tax_currency }} ({{ $expense->rate_source === 'table' ? __('rate table, :date', ['date' => $expense->rate_date?->toDateString()]) : __('entered') }})</dd></div>
                    <div class="flex justify-between gap-4"><dt class="text-muted">{{ __('Total in tax currency') }}</dt><dd><x-ui.amount :value="$expense->gross_tax" :currency="$expense->tax_currency" /></dd></div>
                @endif
                @if ($expense->vat_treatment === \App\Domains\Billing\Enums\VatTreatment::ReverseCharge)
                    <div class="flex justify-between gap-4"><dt class="text-muted">{{ __('Output VAT self-assessed (:rate%)', ['rate' => $rate($expense->vat_rate ?? '0')]) }}</dt><dd><x-ui.amount :value="$expense->self_assessed_vat_tax" :currency="$expense->tax_currency" /></dd></div>
                @endif
                <div class="flex justify-between gap-4"><dt class="text-muted">{{ __('Recoverable input VAT') }}</dt><dd><x-ui.amount :value="$expense->recoverable_vat_tax" :currency="$expense->tax_currency" /></dd></div>
                <div class="flex justify-between gap-4"><dt class="text-muted">{{ __('Paid') }}</dt><dd><x-ui.amount :value="$expense->amount_paid" :currency="$expense->currency" /></dd></div>
                @if ((float) $expense->amount_credited > 0)
                    <div class="flex justify-between gap-4"><dt class="text-muted">{{ __('Credited by the supplier') }}</dt><dd><x-ui.amount :value="$expense->amount_credited" :currency="$expense->currency" /></dd></div>
                @endif
                @if ((float) $expense->amount_written_off > 0)
                    <div class="flex justify-between gap-4"><dt class="text-muted">{{ __('Written off') }}</dt><dd><x-ui.amount :value="$expense->amount_written_off" :currency="$expense->currency" /></dd></div>
                @endif
                @if ((float) $expense->owedBack() > 0)
                    <div class="flex justify-between gap-4"><dt class="text-muted">{{ __('Owed back by the supplier') }}</dt><dd class="font-semibold"><x-ui.amount :value="$expense->owedBack()" :currency="$expense->currency" /></dd></div>
                @endif
                <div class="flex justify-between gap-4"><dt class="text-muted">{{ __('Owed') }}</dt><dd class="font-semibold"><x-ui.amount :value="$expense->counted() ? $expense->balance() : 0" :currency="$expense->currency" /></dd></div>
                @if ($expense->currency !== $expense->tax_currency)
                    <div class="flex justify-between gap-4" data-exchange-difference><dt class="text-muted">{{ __('Exchange gain or loss on payments') }}</dt><dd><x-ui.amount :value="$expense->exchange_difference" :currency="$expense->tax_currency" /></dd></div>
                @endif
            </dl>
            @if ($expense->write_off_reason)
                <p class="mt-4 text-sm text-muted">{{ __('Written off on :date: :reason', ['date' => $expense->written_off_at?->toDateString(), 'reason' => $expense->write_off_reason]) }}</p>
            @endif
        </x-ui.card>

        @php($reversed = $expense->payments->pluck('reverses_id')->filter()->all())
        <x-ui.card :title="__('Payments')" flush>
            <x-ui.table :primary="[0, 2]">
                <x-slot:head>
                    <x-ui.th>{{ __('Paid on') }}</x-ui.th>
                    <x-ui.th>{{ __('How') }}</x-ui.th>
                    <x-ui.th align="right">{{ __('Amount') }}</x-ui.th>
                    <x-ui.th align="right">{{ __('In :currency', ['currency' => $expense->tax_currency]) }}</x-ui.th>
                    <x-ui.th align="right">{{ __('Exchange difference') }}</x-ui.th>
                    <x-ui.th><span class="sr-only">{{ __('Take back') }}</span></x-ui.th>
                </x-slot:head>
                @forelse ($expense->payments as $payment)
                    <tr class="hover:bg-sunken">
                        <td class="px-6 py-3 text-sm text-fg">{{ $payment->paid_on->toDateString() }}</td>
                        <td class="px-6 py-3 text-sm text-muted">{{ $payment->channel->label() }}
                            @if ($payment->reference)
                                <span class="block text-xs" dir="ltr">{{ $payment->reference }}</span>
                            @endif
                        </td>
                        <td class="px-6 py-3 text-right text-sm"><x-ui.amount :value="$payment->amount" :currency="$payment->currency" /></td>
                        <td class="px-6 py-3 text-right text-sm"><x-ui.amount :value="$payment->amount_tax" />
                            @if ($payment->exchange_rate !== null)
                                <span class="block text-xs text-muted" dir="ltr">@ {{ rtrim(rtrim((string) $payment->exchange_rate, '0'), '.') }}</span>
                            @endif
                        </td>
                        <td class="px-6 py-3 text-right text-sm"><x-ui.amount :value="$payment->exchange_difference" /></td>
                        <td class="px-6 py-3 text-right text-sm">
                            @if ($canManage && $payment->reverses_id === null && (float) $payment->amount > 0 && ! in_array($payment->id, $reversed, true) && $expense->status !== \App\Domains\Billing\Enums\ExpenseStatus::WrittenOff)
                                <form method="POST" action="{{ route('admin.finance.expenses.payments.reverse', [$expense->id, $payment->id]) }}" class="flex flex-wrap items-center justify-end gap-2"
                                      data-confirm="{{ __('Take back the payment of :amount?', ['amount' => $payment->amount.' '.$payment->currency]) }}"
                                      data-confirm-detail="{{ __('Recorded in error: it is answered by the same amount below zero today, and the expense is owed again. This cannot be undone.') }}"
                                      data-confirm-action="{{ __('Take back') }}" data-confirm-tone="danger">
                                    @csrf
                                    <x-ui.input name="reason" maxlength="500" required :placeholder="__('Why')" :aria-label="__('Why')" />
                                    <x-ui.button variant="ghost" size="sm">{{ __('Take back') }}</x-ui.button>
                                </form>
                            @elseif ($payment->reverses_id !== null || in_array($payment->id, $reversed, true))
                                <span class="text-xs text-muted">{{ $payment->reverses_id !== null ? __('Taken back') : __('Reversed') }}</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <x-ui.empty :columns="6">{{ __('Nothing paid yet.') }}</x-ui.empty>
                @endforelse
            </x-ui.table>
        </x-ui.card>

        <x-ui.card :title="__('Credit notes from the supplier')" flush>
            <x-ui.table :primary="[0, 2]">
                <x-slot:head>
                    <x-ui.th>{{ __('Date') }}</x-ui.th>
                    <x-ui.th>{{ __('Number') }}</x-ui.th>
                    <x-ui.th align="right">{{ __('Amount') }}</x-ui.th>
                    <x-ui.th align="right">{{ __('Input VAT taken back') }}</x-ui.th>
                </x-slot:head>
                @forelse ($expense->creditNotes as $note)
                    <tr class="hover:bg-sunken">
                        <td class="px-6 py-3 text-sm text-fg">{{ $note->credit_date->toDateString() }}</td>
                        <td class="px-6 py-3 text-sm text-muted"><span dir="ltr">{{ $note->supplier_credit_number ?? '-' }}</span>
                            <span class="block text-xs">{{ $note->reason }}</span>
                        </td>
                        <td class="px-6 py-3 text-right text-sm"><x-ui.amount :value="$note->gross" :currency="$note->currency" /></td>
                        <td class="px-6 py-3 text-right text-sm"><x-ui.amount :value="$note->recoverable_vat_tax" :currency="$expense->tax_currency" /></td>
                    </tr>
                @empty
                    <x-ui.empty :columns="4">{{ __('No credit note from the supplier.') }}</x-ui.empty>
                @endforelse
            </x-ui.table>
        </x-ui.card>
    </div>

    <div class="space-y-6">
        <x-ui.card :title="__('Receipt')">
            @if ($expense->receipt_path)
                @if (\App\Domains\Billing\Services\ExpenseReceipts::isImage($expense->receipt_mime))
                    <img src="{{ route('admin.finance.expenses.receipt.preview', $expense->id) }}" alt="{{ __('Receipt: :name', ['name' => $expense->receipt_name]) }}" class="mb-3 max-h-96 w-full rounded-card border border-line object-contain" loading="lazy">
                @else
                    <x-ui.button variant="secondary" size="sm" :href="route('admin.finance.expenses.receipt.preview', $expense->id)" target="_blank" rel="noopener">{{ __('Open the PDF') }}</x-ui.button>
                @endif
                <p class="mt-2 text-sm"><a href="{{ route('admin.finance.expenses.receipt', $expense->id) }}" class="text-accent hover:underline">{{ __('Download :name', ['name' => $expense->receipt_name]) }}</a></p>
            @else
                <p class="text-sm text-muted">{{ __('No receipt yet.') }}</p>
            @endif
        </x-ui.card>

        @if ($canManage && $expense->status === \App\Domains\Billing\Enums\ExpenseStatus::Draft)
            <x-ui.card :title="__('Record it')">
                <form method="POST" action="{{ route('admin.finance.expenses.confirm', $expense->id) }}"
                      data-confirm="{{ __('Record this expense of :amount?', ['amount' => $expense->gross.' '.$expense->currency]) }}"
                      data-confirm-detail="{{ __('It counts in the reports and is owed to the supplier from now on.') }}"
                      data-confirm-action="{{ __('Record') }}">
                    @csrf
                    <x-ui.button>{{ __('Record') }}</x-ui.button>
                </form>
            </x-ui.card>
        @endif

        @if ($canManage && $expense->owed())
            <x-ui.card :title="__('Pay')" :description="__('Owed: :amount. A payment of :threshold :currency or more waits for a second admin while its approval policy is on.', ['amount' => $expense->balance().' '.$expense->currency, 'threshold' => $threshold, 'currency' => $expense->tax_currency])">
                <form method="POST" action="{{ route('admin.finance.expenses.pay', $expense->id) }}" class="space-y-3"
                      data-confirm="{{ __('Record a payment to :supplier?', ['supplier' => $expense->supplier?->name]) }}"
                      data-confirm-detail="{{ __('What is owed on this expense goes down by the amount. A payment is never changed once recorded.') }}"
                      data-confirm-action="{{ __('Record payment') }}">
                    @csrf
                    <x-ui.field :label="__('Amount (:currency)', ['currency' => $expense->currency])" error="amount">
                        <x-ui.input type="number" name="amount" step="0.01" min="0.01" required value="{{ old('amount', $expense->balance()) }}" />
                    </x-ui.field>
                    <x-ui.field :label="__('Paid on')" error="paid_on">
                        <x-ui.date-input name="paid_on" required value="{{ old('paid_on', now()->toDateString()) }}" />
                    </x-ui.field>
                    <x-ui.field :label="__('How')" error="channel">
                        <x-ui.select name="channel" required>
                            @foreach (\App\Domains\Billing\Enums\OutgoingChannel::options() as $value => $label)
                                <option value="{{ $value }}" @selected(old('channel') === $value)>{{ $label }}</option>
                            @endforeach
                        </x-ui.select>
                    </x-ui.field>
                    @if ($expense->currency !== $expense->tax_currency)
                        <x-ui.field :label="__('Rate to :currency that day', ['currency' => $expense->tax_currency])" error="exchange_rate" optional :hint="__('Empty: the rate table\'s on the payment date.')">
                            <x-ui.input type="number" name="exchange_rate" step="0.000001" min="0" dir="ltr" value="{{ old('exchange_rate') }}" />
                        </x-ui.field>
                    @endif
                    <x-ui.field :label="__('Reference on the statement')" error="reference" optional>
                        <x-ui.input name="reference" maxlength="100" dir="ltr" value="{{ old('reference') }}" />
                    </x-ui.field>
                    <x-ui.field :label="__('Paid from')" error="paid_from" optional :hint="__('The bank account or card it went out of.')">
                        <x-ui.input name="paid_from" maxlength="120" value="{{ old('paid_from') }}" />
                    </x-ui.field>
                    @include('admin.partials.approval-reason', ['action' => \App\Domains\Platform\Approvals\Actions\ExpenseActions::PAY])
                    <x-ui.button>{{ __('Record payment') }}</x-ui.button>
                </form>
            </x-ui.card>

            <x-ui.card :title="__('Write off')" :description="__('What is still owed and will not be paid: the supplier waived it, or it is disputed for good.')">
                <form method="POST" action="{{ route('admin.finance.expenses.write-off', $expense->id) }}" class="space-y-3"
                      data-confirm="{{ __('Write off :amount owed to :supplier?', ['amount' => $expense->balance().' '.$expense->currency, 'supplier' => $expense->supplier?->name]) }}"
                      data-confirm-detail="{{ __('It stops counting as owed. This cannot be undone.') }}"
                      data-confirm-action="{{ __('Write off') }}" data-confirm-tone="danger">
                    @csrf
                    <x-ui.field :label="__('Why')" error="reason">
                        <x-ui.textarea name="reason" rows="2" maxlength="500" required>{{ old('reason') }}</x-ui.textarea>
                    </x-ui.field>
                    <x-ui.button variant="danger">{{ __('Write off') }}</x-ui.button>
                </form>
            </x-ui.card>
        @endif

        @if ($canManage && $expense->counted() && (float) $expense->gross - (float) $expense->amount_credited - (float) $expense->amount_written_off > 0)
            <x-ui.card :title="__('Supplier credit note')" :description="__('What the supplier took off this bill: what is owed goes down, and the input VAT it deducted is taken back in the month of the note.')">
                <form method="POST" action="{{ route('admin.finance.expenses.credit-notes.store', $expense->id) }}" class="space-y-3"
                      data-confirm="{{ __('Record a credit note from :supplier?', ['supplier' => $expense->supplier?->name]) }}"
                      data-confirm-detail="{{ __('It is kept as entered and counted in the reports from its date.') }}"
                      data-confirm-action="{{ __('Record credit note') }}">
                    @csrf
                    <x-ui.field :label="__('Before VAT (:currency)', ['currency' => $expense->currency])" error="net">
                        <x-ui.input type="number" name="net" step="0.01" min="0.01" required value="{{ old('net') }}" />
                    </x-ui.field>
                    @if ($expense->vat_treatment->charged())
                        <x-ui.field :label="__('VAT (:currency)', ['currency' => $expense->currency])" error="vat" optional>
                            <x-ui.input type="number" name="vat" step="0.01" min="0" value="{{ old('vat') }}" />
                        </x-ui.field>
                    @endif
                    <x-ui.field :label="__('Date')" error="credit_date">
                        <x-ui.date-input name="credit_date" required value="{{ old('credit_date', now()->toDateString()) }}" />
                    </x-ui.field>
                    <x-ui.field :label="__('Supplier credit note number')" error="supplier_credit_number" optional>
                        <x-ui.input name="supplier_credit_number" maxlength="100" dir="ltr" value="{{ old('supplier_credit_number') }}" />
                    </x-ui.field>
                    <x-ui.field :label="__('Why')" error="reason">
                        <x-ui.textarea name="reason" rows="2" maxlength="500" required>{{ old('reason') }}</x-ui.textarea>
                    </x-ui.field>
                    <x-ui.button>{{ __('Record credit note') }}</x-ui.button>
                </form>
            </x-ui.card>
        @endif

        @if ($canManage && $expense->editable())
            <x-ui.card :title="__('Void')" :description="__('Entered by mistake: it is kept, with why, and counted nowhere.')">
                <form method="POST" action="{{ route('admin.finance.expenses.void', $expense->id) }}" class="space-y-3"
                      data-confirm="{{ __('Void this expense of :amount?', ['amount' => $expense->gross.' '.$expense->currency]) }}"
                      data-confirm-detail="{{ __('It stops counting anywhere. This cannot be undone; enter it again if it was right.') }}"
                      data-confirm-action="{{ __('Void') }}" data-confirm-tone="danger">
                    @csrf
                    <x-ui.field :label="__('Why')" error="reason">
                        <x-ui.textarea name="reason" rows="2" maxlength="500" required>{{ old('reason') }}</x-ui.textarea>
                    </x-ui.field>
                    <x-ui.button variant="danger">{{ __('Void') }}</x-ui.button>
                </form>
            </x-ui.card>
        @endif
    </div>
</div>
@endsection
