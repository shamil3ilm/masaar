@extends('layouts.portal')

@section('title', $schedule->name)

@php($status = $schedule->status)
@php($running = in_array($status, [\App\Domains\Invoice\Enums\ScheduleStatus::Active, \App\Domains\Invoice\Enums\ScheduleStatus::Paused], true))
@php($lines = array_values((array) ($schedule->template['lines'] ?? [])))

@section('content')
<x-ui.page-header :title="$schedule->name" class="mb-8">
    <x-slot:breadcrumb>
        <x-ui.breadcrumb :items="[['label' => __('Schedules'), 'href' => route('portal.schedules.index')], ['label' => $schedule->name]]" />
    </x-slot:breadcrumb>
    <x-slot:description><span dir="auto">{{ $schedule->buyerName() }}</span></x-slot:description>
    <x-slot:actions>
        <x-ui.badge :state="$status" />
        @if ($status === \App\Domains\Invoice\Enums\ScheduleStatus::Active)
            <form method="POST" action="{{ route('portal.schedules.pause', $schedule->id) }}">
                @csrf
                <x-ui.button size="sm" variant="secondary">{{ __('Pause') }}</x-ui.button>
            </form>
        @elseif ($status === \App\Domains\Invoice\Enums\ScheduleStatus::Paused)
            <form method="POST" action="{{ route('portal.schedules.resume', $schedule->id) }}"
                  data-confirm="{{ __('Resume :name?', ['name' => $schedule->name]) }}"
                  data-confirm-detail="{{ __('Periods that began while it was paused are not billed. It carries on from the next period to begin.') }}"
                  data-confirm-action="{{ __('Resume') }}">
                @csrf
                <x-ui.button size="sm">{{ __('Resume') }}</x-ui.button>
            </form>
        @endif
        @if ($running && ! $instalments)
            <form method="POST" action="{{ route('portal.schedules.skip', $schedule->id) }}"
                  data-confirm="{{ __('Skip the next period?') }}"
                  data-confirm-detail="{{ __('No invoice is made for it. This cannot be undone.') }}"
                  data-confirm-action="{{ __('Skip period') }}">
                @csrf
                <x-ui.button size="sm" variant="secondary">{{ __('Skip next period') }}</x-ui.button>
            </form>
        @endif
        @if ($running)
            <form method="POST" action="{{ route('portal.schedules.cancel', $schedule->id) }}"
                  data-confirm="{{ __('Cancel :name?', ['name' => $schedule->name]) }}"
                  data-confirm-detail="{{ __('No more invoices are made. Invoices already made are not affected. This cannot be undone.') }}"
                  data-confirm-action="{{ __('Cancel schedule') }}"
                  data-confirm-tone="danger">
                @csrf
                <x-ui.button size="sm" variant="danger">{{ __('Cancel schedule') }}</x-ui.button>
            </form>
        @endif
    </x-slot:actions>
</x-ui.page-header>

@if ($schedule->last_error)
    <x-ui.alert tone="warning" :title="__('The last run failed')" class="mb-6">
        <p dir="auto">{{ $schedule->last_error }}</p>
        <p class="mt-1 text-xs">{{ __('It is tried again automatically. Your administrators are emailed if it keeps failing.') }}</p>
    </x-ui.alert>
@endif

<div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-4">
    <x-ui.stat :label="__('How often')" :value="$schedule->frequency->label().' · '.$schedule->bills_in->label()" />
    <x-ui.stat :label="__('Next run')" :value="$schedule->next_run_on?->toDateString() ?? '-'" />
    <x-ui.stat :label="__('Each invoice')" :value="$schedule->auto_issue ? __('Issued as made') : __('Draft for review')" />
    <x-ui.stat :label="__('Plan')" :value="match ($schedule->instalment_model) {
        \App\Domains\Invoice\Enums\InstalmentModel::Prepayment => __('Instalments paid ahead'),
        \App\Domains\Invoice\Enums\InstalmentModel::Delivered => __('Delivered up front, paid in instalments'),
        \App\Domains\Invoice\Enums\InstalmentModel::Periodic => __('Instalments'),
        default => __('Whole invoice each period'),
    }" />
</div>

<x-ui.section :title="__('Upcoming runs')" :description="__('As they would be made today. A failing rule is shown before the run fails.')" flush class="mb-6">
    <x-ui.table :primary="[0, 2]">
        <x-slot:head>
            <x-ui.th>{{ __('Period') }}</x-ui.th>
            <x-ui.th>{{ __('Runs on') }}</x-ui.th>
            <x-ui.th align="right">{{ __('Total') }}</x-ui.th>
        </x-slot:head>
        @forelse ($upcoming as $run)
        <tr>
            <td class="px-6 py-3 text-sm"><x-ui.date :value="$run['start_date']" /> – <x-ui.date :value="$run['end_date']" />
                @if ($run['document_type'] === 'prepayment')<div class="text-xs text-muted">{{ __('Prepayment invoice') }}</div>@endif
                @if ($run['settles_advances'])<div class="text-xs text-muted">{{ __('Final invoice, less the advances') }}</div>@endif
            </td>
            <td class="px-6 py-3 text-sm"><x-ui.date :value="$run['runs_on']" /></td>
            <td class="px-6 py-3 text-end text-sm">
                @if ($run['problem'])
                    <span class="text-warning" dir="auto">{{ $run['problem'] }}</span>
                @else
                    @if ($run['total'] !== null)<x-ui.amount :value="$run['total']" :currency="$run['currency']" />@endif
                    @if (($run['payment_due'] ?? null) !== null)
                        <div class="text-xs text-muted">{{ __('Payment due') }}: <x-ui.amount :value="$run['payment_due']" :currency="$run['currency']" /></div>
                    @endif
                @endif
            </td>
        </tr>
        @empty
        <x-ui.empty :columns="3">{{ __('No more runs.') }}</x-ui.empty>
        @endforelse
    </x-ui.table>
</x-ui.section>

<x-ui.section :title="__('Runs so far')" flush class="mb-6">
    <x-ui.table :primary="[0, 2]">
        <x-slot:head>
            <x-ui.th>{{ __('Period') }}</x-ui.th>
            <x-ui.th>{{ __('Invoice') }}</x-ui.th>
            <x-ui.th align="right">{{ __('Status') }}</x-ui.th>
        </x-slot:head>
        @forelse ($schedule->runs->sortByDesc('period') as $run)
        <tr>
            <td class="px-6 py-3 text-sm"><x-ui.date :value="$run->period_start" /> – <x-ui.date :value="$run->period_end" /></td>
            <td class="px-6 py-3 text-sm">
                @if ($run->invoice)
                    <a href="{{ route('portal.invoices.show', $run->invoice->id) }}" class="text-accent hover:underline">{{ $run->invoice->invoice_number ?? __('Draft') }}</a>
                    · <x-ui.amount :value="$run->invoice->total" :currency="$run->invoice->currency" />
                @else
                    -
                @endif
                @if ($run->error)<div class="text-xs text-warning" dir="auto">{{ $run->error }}</div>@endif
                @if ($run->amount_due !== null)
                    <div class="mt-1 text-xs text-muted">
                        {{ __('Payment due') }}: <x-ui.amount :value="$run->amount_due" :currency="$run->invoice?->currency" />
                        @if ($run->paid_at)
                            · {{ __('paid :date', ['date' => $run->paid_at->toDateString()]) }}
                        @else
                            <form method="POST" action="{{ route('portal.schedules.paid', [$schedule->id, $run->id]) }}" class="inline"
                                  data-confirm="{{ __('Record this instalment as paid?') }}"
                                  data-confirm-detail="{{ __('It is a payment against the plan\'s invoice. The record cannot be taken back.') }}"
                                  data-confirm-action="{{ __('Record paid') }}">
                                @csrf
                                <x-ui.button size="sm" variant="secondary">{{ __('Record paid') }}</x-ui.button>
                            </form>
                        @endif
                    </div>
                @endif
            </td>
            <td class="px-6 py-3 text-end text-sm"><x-ui.badge :state="$run->status" /></td>
        </tr>
        @empty
        <x-ui.empty :columns="3">{{ __('Nothing has run yet.') }}</x-ui.empty>
        @endforelse
    </x-ui.table>
</x-ui.section>

@if ($running && ! $instalments && $lines !== [])
    <x-ui.section collapse :title="__('Change the prices')" :description="__('From the next period on. Invoices already made keep their prices.')">
        <form method="POST" action="{{ route('portal.schedules.prices', $schedule->id) }}" class="space-y-4"
              data-confirm="{{ __('Change the prices from the next period on?') }}"
              data-confirm-detail="{{ __('Invoices already made keep their prices.') }}"
              data-confirm-action="{{ __('Change prices') }}">
            @csrf
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                @foreach ($lines as $index => $line)
                    <x-ui.field :label="(string) ($line['description'] ?? $line['item_name'] ?? __('Line :number', ['number' => $index + 1]))" error="prices.{{ $index }}">
                        <x-ui.input type="number" step="0.01" min="0" name="prices[{{ $index }}]" required value="{{ old('prices.'.$index, $line['unit_price'] ?? '') }}" />
                    </x-ui.field>
                @endforeach
            </div>
            <x-ui.button variant="secondary">{{ __('Change prices') }}</x-ui.button>
        </form>
    </x-ui.section>
@endif
@endsection
