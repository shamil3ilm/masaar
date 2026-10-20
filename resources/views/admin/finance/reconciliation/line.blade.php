@extends('layouts.admin')

@section('title', __('Statement line'))

@php
    $canChange = auth()->user()?->canAdmin('billing.manage');
    $open = $line->isCredit() && in_array($line->status->value, ['unmatched', 'suggested'], true);
    $title = $line->reference ?? $line->bank_reference ?? __('Statement line');
@endphp

@section('content')
<x-ui.page-header :title="$title" :description="__('A line of the statement, what it was matched to, and what can be done with it.')">
    <x-slot:breadcrumb>
        <x-ui.breadcrumb :items="[['label' => __('Statement lines'), 'href' => route('admin.finance.reconciliation.lines')], ['label' => $title]]" />
    </x-slot:breadcrumb>
    <x-slot:meta>
        <x-ui.badge :tone="$line->isCredit() ? $line->status->tone() : 'neutral'" :label="$line->isCredit() ? $line->status->label() : __('Money out')" />
    </x-slot:meta>
</x-ui.page-header>

@error('reconciliation')
    <x-ui.alert tone="danger" class="mb-6">{{ $message }}</x-ui.alert>
@enderror

<div class="grid max-w-6xl grid-cols-1 gap-6 lg:grid-cols-3">
    <div class="space-y-6 lg:col-span-2">
        <x-ui.card :title="__('Matches')" :description="__('Suggestions wait for an admin. One below :high% sure is confirmed only after you say you checked it.', ['high' => $high])" flush>
            <x-ui.table :primary="[0, 1, 3]">
                <x-slot:head>
                    <x-ui.th>{{ __('Customer') }}</x-ui.th>
                    <x-ui.th>{{ __('Matched to') }}</x-ui.th>
                    <x-ui.th>{{ __('Why') }}</x-ui.th>
                    <x-ui.th>{{ __('Status') }}</x-ui.th>
                    <x-ui.th align="right">{{ __('Actions') }}</x-ui.th>
                </x-slot:head>
                @forelse ($matches as $row)
                    @php($match = $row['match'])
                    <tr class="align-top hover:bg-sunken">
                        <td class="px-6 py-4 text-sm text-fg">{{ $row['customer'] }}
                            <span class="block text-xs text-muted">{{ $match->rule->label() }} · {{ __(':confidence% sure', ['confidence' => $match->confidence]) }}</span>
                        </td>
                        <td class="px-6 py-4 text-sm text-fg">
                            @foreach ($row['targets'] as $target)
                                <span class="block" dir="ltr">
                                    {{ match ($target['kind']) { 'invoice' => __('Invoice'), 'payment' => __('Payment'), default => __('Recorded payment') } }}
                                    {{ $target['label'] }}: <x-ui.amount :value="$target['amount']" :currency="$match->currency" />
                                </span>
                            @endforeach
                            @if ($row['lines'] > 1)
                                <span class="block text-xs text-muted">{{ __('With :count lines together', ['count' => $row['lines']]) }}</span>
                            @endif
                            @if ($match->isShort())
                                <span class="block text-xs text-warning">{{ __('Short by') }} <x-ui.amount :value="abs((float) $match->difference + (float) $match->fee_amount)" :currency="$match->currency" /></span>
                            @elseif ($match->isOver())
                                <span class="block text-xs text-info">{{ __('Over by') }} <x-ui.amount :value="$match->difference" :currency="$match->currency" /> {{ __('(kept to the customer\'s credit)') }}</span>
                            @endif
                            @if ($match->fee_amount !== null)
                                <span class="block text-xs text-muted">{{ __('Bank fee') }} <x-ui.amount :value="$match->fee_amount" :currency="$match->currency" /></span>
                            @endif
                        </td>
                        <td class="px-6 py-4 text-xs text-muted">
                            @foreach ($match->reasons() as $reason)
                                <span class="block">{{ $reason }}</span>
                            @endforeach
                        </td>
                        <td class="px-6 py-4">
                            <x-ui.badge :tone="$match->status->tone()" :label="$match->status->label()" />
                            @if ($match->auto_confirmed)
                                <span class="block text-xs text-muted">{{ __('Confirmed automatically') }}</span>
                            @endif
                        </td>
                        <td class="px-6 py-4 text-right text-sm">
                            @if ($canChange && $match->status->value === 'suggested')
                                <div class="flex flex-wrap justify-end gap-2">
                                    @if ($match->confidence < $high)
                                        <form method="POST" action="{{ route('admin.finance.reconciliation.match.confirm', $match->id) }}"
                                              data-confirm="{{ __('Confirm a match that is :confidence% sure?', ['confidence' => $match->confidence]) }}"
                                              data-confirm-detail="{{ __('Check the line against the invoices first. Confirming records the payment for :customer; it can be undone.', ['customer' => $row['customer']]) }}"
                                              data-confirm-action="{{ __('Confirm match') }}">
                                            @csrf
                                            <input type="hidden" name="checked" value="1">
                                            <x-ui.button size="sm">{{ __('Confirm') }}</x-ui.button>
                                        </form>
                                    @else
                                        <form method="POST" action="{{ route('admin.finance.reconciliation.match.confirm', $match->id) }}">
                                            @csrf
                                            <x-ui.button size="sm">{{ __('Confirm') }}</x-ui.button>
                                        </form>
                                    @endif
                                    <form method="POST" action="{{ route('admin.finance.reconciliation.match.reject', $match->id) }}"
                                          data-confirm="{{ __('Reject this suggestion?') }}"
                                          data-confirm-detail="{{ __('It is not suggested for this line again. The line can still be matched by hand.') }}"
                                          data-confirm-action="{{ __('Reject') }}" data-confirm-tone="danger">
                                        @csrf
                                        <x-ui.button variant="ghost" size="sm">{{ __('Reject') }}</x-ui.button>
                                    </form>
                                </div>
                                @if (collect($row['targets'])->contains('kind', 'invoice') && $row['lines'] === 1)
                                    <x-ui.disclosure :summary="__('Split differently')" class="mt-2 text-left">
                                        <form method="POST" action="{{ route('admin.finance.reconciliation.match.split', $match->id) }}" class="space-y-2"
                                              data-confirm="{{ __('Match the line with these amounts?') }}"
                                              data-confirm-detail="{{ __('The payment is recorded with your amounts; what is left of the line goes to the customer\'s credit. It can be undone.') }}"
                                              data-confirm-action="{{ __('Match') }}">
                                            @csrf
                                            @foreach ($row['targets'] as $target)
                                                @if ($target['kind'] === 'invoice')
                                                    <x-ui.field :label="__('Invoice :number', ['number' => $target['label']])">
                                                        <x-ui.input type="number" step="0.01" min="0" dir="ltr" name="amounts[{{ $target['id'] }}]" value="{{ $target['amount'] }}" />
                                                    </x-ui.field>
                                                @endif
                                            @endforeach
                                            <x-ui.button variant="secondary" size="sm">{{ __('Match with these amounts') }}</x-ui.button>
                                        </form>
                                    </x-ui.disclosure>
                                @endif
                            @elseif ($canChange && $match->status->value === 'confirmed')
                                <form method="POST" action="{{ route('admin.finance.reconciliation.match.unmatch', $match->id) }}" class="space-y-2 text-left"
                                      data-confirm="{{ __('Undo this match?') }}"
                                      data-confirm-detail="{{ __('A payment it recorded is reversed and the invoices owe it again; a payment it only claimed is left as it is. The line is unmatched again.') }}"
                                      data-confirm-action="{{ __('Undo match') }}" data-confirm-tone="danger">
                                    @csrf
                                    <x-ui.field :label="__('Why')" error="reason">
                                        <x-ui.input name="reason" maxlength="500" required />
                                    </x-ui.field>
                                    <x-ui.button variant="danger" size="sm">{{ __('Undo match') }}</x-ui.button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @empty
                    <x-ui.empty :columns="5">{{ __('Nothing suggested for this line.') }}</x-ui.empty>
                @endforelse
            </x-ui.table>
        </x-ui.card>

        @if ($canChange && $open)
            <x-ui.card :title="__('Match by hand')" :description="__('Choose the customer, then how much of the line goes to each of its invoices, or the payment already recorded that this line is.')">
                <form method="GET" action="{{ route('admin.finance.reconciliation.line', $line->id) }}" class="flex flex-wrap items-end gap-4">
                    <x-ui.field :label="__('Customer')" class="min-w-64">
                        <x-ui.select name="customer">
                            <option value="">{{ __('Choose a customer') }}</option>
                            @foreach ($manual['customers'] as $id => $name)
                                <option value="{{ $id }}" @selected($customer === $id)>{{ $name }}</option>
                            @endforeach
                        </x-ui.select>
                    </x-ui.field>
                    <x-ui.button variant="secondary">{{ __('Show') }}</x-ui.button>
                </form>

                @if ($customer !== '')
                    <form method="POST" action="{{ route('admin.finance.reconciliation.line.match', $line->id) }}" class="mt-5 space-y-4"
                          data-confirm="{{ __('Match the line by hand?') }}"
                          data-confirm-detail="{{ __('The payment is recorded against the invoices you chose, or the payment you chose is claimed. It can be undone.') }}"
                          data-confirm-action="{{ __('Match') }}">
                        @csrf
                        <input type="hidden" name="organization" value="{{ $customer }}">
                        @forelse ($manual['invoices'] as $row)
                            <x-ui.field :label="__('Invoice :number, owes :balance', ['number' => $row['number'], 'balance' => $row['balance'].' '.$line->currency])">
                                <x-ui.input type="number" step="0.01" min="0" dir="ltr" name="amounts[{{ $row['receivable']->id }}]" />
                            </x-ui.field>
                        @empty
                            <p class="text-sm text-muted">{{ __('This customer owes nothing in :currency.', ['currency' => $line->currency]) }}</p>
                        @endforelse
                        @foreach ($manual['payments'] as $payment)
                            <x-ui.checkbox name="payments[]" value="{{ $payment->id }}">
                                {{ __('Payment :reference of :date', ['reference' => $payment->reference ?? $payment->gateway_reference, 'date' => $payment->paid_at->toDateString()]) }}
                                · <x-ui.amount :value="$payment->total" :currency="$payment->currency" />
                            </x-ui.checkbox>
                        @endforeach
                        @if ($line->source !== \App\Domains\Billing\Reconciliation\Models\StatementImport::GATEWAY)
                            <x-ui.field :label="__('Bank fee')" error="fee" :hint="__('What the bank kept, when the line is short of the invoices by it. The customer is credited in full.')" optional>
                                <x-ui.input type="number" step="0.01" min="0" dir="ltr" name="fee" class="max-w-40" />
                            </x-ui.field>
                        @endif
                        <x-ui.button>{{ __('Match') }}</x-ui.button>
                    </form>
                @endif
            </x-ui.card>
        @endif
    </div>

    <div class="space-y-6">
        <x-ui.card :title="__('The line')">
            <dl class="space-y-3 text-sm">
                <div><dt class="text-muted">{{ __('Booked') }}</dt><dd class="text-fg"><x-ui.date :value="$line->booked_on" /></dd></div>
                @if ($line->value_on)
                    <div><dt class="text-muted">{{ __('Value date') }}</dt><dd class="text-fg"><x-ui.date :value="$line->value_on" /></dd></div>
                @endif
                <div><dt class="text-muted">{{ $line->isCredit() ? __('Money in') : __('Money out') }}</dt><dd class="text-fg"><x-ui.amount :value="$line->amount" :currency="$line->currency" /></dd></div>
                @if ($line->fee_amount !== null)
                    <div><dt class="text-muted">{{ __('Gateway fee') }}</dt><dd class="text-fg"><x-ui.amount :value="$line->fee_amount" :currency="$line->currency" /></dd></div>
                @endif
                <div><dt class="text-muted">{{ __('Reference') }}</dt><dd class="text-fg" dir="ltr">{{ $line->reference ?? '-' }}</dd></div>
                <div><dt class="text-muted">{{ __('Bank reference') }}</dt><dd class="text-fg" dir="ltr">{{ $line->bank_reference ?? '-' }}</dd></div>
                <div><dt class="text-muted">{{ __('Payer') }}</dt><dd class="text-fg">{{ $line->counterparty_name ?? '-' }}
                    @if ($line->counterparty_account)<span class="block text-xs text-muted" dir="ltr">{{ $line->counterparty_account }}</span>@endif
                </dd></div>
                <div><dt class="text-muted">{{ __('Description') }}</dt><dd class="text-fg">{{ $line->description ?? '-' }}</dd></div>
                <div><dt class="text-muted">{{ __('Statement') }}</dt><dd class="text-fg">{{ $line->statementImport?->kind->label() }}
                    <span class="block text-xs text-muted" dir="ltr">{{ $line->statementImport?->statement_reference ?? $line->statementImport?->file_name }}</span>
                </dd></div>
            </dl>
        </x-ui.card>

        @if ($canChange && $line->status->value === 'ignored')
            <x-ui.card :title="__('Set aside')" :description="$line->ignored_reason ?? __('Money out: not a customer\'s payment.')">
                <form method="POST" action="{{ route('admin.finance.reconciliation.line.restore', $line->id) }}"
                      data-confirm="{{ __('Bring this line back to be matched?') }}"
                      data-confirm-detail="{{ __('It is listed as unmatched again until it is matched or set aside.') }}"
                      data-confirm-action="{{ __('Bring back') }}">
                    @csrf
                    <x-ui.button variant="secondary">{{ __('Bring back') }}</x-ui.button>
                </form>
            </x-ui.card>
        @elseif ($canChange && $open)
            <x-ui.card :title="__('Not a customer\'s payment?')" :description="__('A transfer between the platform\'s own accounts, a supplier\'s refund: set it aside so it is not listed as unmatched.')">
                <form method="POST" action="{{ route('admin.finance.reconciliation.line.suggest', $line->id) }}" class="mb-4">
                    @csrf
                    <x-ui.button variant="secondary" size="sm">{{ __('Look for matches again') }}</x-ui.button>
                </form>
                <form method="POST" action="{{ route('admin.finance.reconciliation.line.ignore', $line->id) }}" class="space-y-3"
                      data-confirm="{{ __('Set this line aside?') }}"
                      data-confirm-detail="{{ __('Its suggestions are dropped and it is no longer listed as unmatched. You can bring it back.') }}"
                      data-confirm-action="{{ __('Set aside') }}">
                    @csrf
                    <x-ui.field :label="__('Why')" error="reason">
                        <x-ui.input name="reason" maxlength="500" required />
                    </x-ui.field>
                    <x-ui.button variant="ghost">{{ __('Set aside') }}</x-ui.button>
                </form>
            </x-ui.card>
        @endif
    </div>
</div>
@endsection
