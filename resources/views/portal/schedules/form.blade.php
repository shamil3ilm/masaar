@extends('layouts.portal')

@section('title', __('New schedule'))

@section('content')
<x-ui.page-header :title="__('New schedule')" :description="__('The invoice is made again each period with the same buyer, lines and terms. Each one states the period it bills.')">
    <x-slot:breadcrumb>
        <x-ui.breadcrumb :items="[['label' => __('Schedules'), 'href' => route('portal.schedules.index')], ['label' => __('New schedule')]]" />
    </x-slot:breadcrumb>
</x-ui.page-header>

<form method="POST" action="{{ route('portal.schedules.store') }}" class="max-w-3xl space-y-6">
    @csrf

    <x-ui.section :title="__('Invoice to repeat')">
        @if ($invoice)
            <input type="hidden" name="source_invoice_id" value="{{ $invoice->id }}">
            <p class="text-sm text-fg">
                <a href="{{ route('portal.invoices.show', $invoice->id) }}" class="font-medium text-accent hover:underline">{{ $invoice->invoice_number ?? __('Draft') }}</a>
                · <span dir="auto">{{ $invoice->buyer_name }}</span>
                · <x-ui.amount :value="$invoice->total" :currency="$invoice->currency" />
            </p>
        @else
            <x-ui.field :label="__('Invoice')" error="source_invoice_id" :hint="__('Its buyer, lines, charges and terms are copied to the schedule.')">
                <x-ui.select searchable name="source_invoice_id" required>
                    <option value="">{{ __('Choose an invoice') }}</option>
                    @foreach ($choices as $choice)
                        <option value="{{ $choice->id }}" @selected(old('source_invoice_id') === $choice->id)>{{ ($choice->invoice_number ?? __('Draft')).' · '.$choice->buyer_name.' · '.$choice->issue_date?->toDateString() }}</option>
                    @endforeach
                </x-ui.select>
            </x-ui.field>
        @endif
    </x-ui.section>

    <x-ui.section :title="__('When it runs')">
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <x-ui.field :label="__('Name')" error="name">
                <x-ui.input type="text" name="name" maxlength="120" required value="{{ old('name', $invoice?->buyer_name) }}" />
            </x-ui.field>
            <x-ui.field :label="__('How often')" error="frequency">
                <x-ui.select name="frequency">
                    @foreach ($frequencies as $value => $label)
                        <option value="{{ $value }}" @selected(old('frequency', $frequency->value) === $value)>{{ $label }}</option>
                    @endforeach
                </x-ui.select>
            </x-ui.field>
            <x-ui.field :label="__('First period starts')" error="start_date" :hint="__('Each period is invoiced on its first day. Not needed for terms.')">
                <x-ui.date-input name="start_date" value="{{ old('start_date', $today) }}" />
            </x-ui.field>
            <x-ui.field :label="__('Ends after')" error="occurrences" optional :hint="__('A number of periods, or leave it empty and give an end date - or neither, to run until you stop it.')">
                <x-ui.input type="number" name="occurrences" min="1" max="600" value="{{ old('occurrences') }}" />
            </x-ui.field>
            <x-ui.field :label="__('Last period starts by')" error="end_date" optional>
                <x-ui.date-input name="end_date" value="{{ old('end_date') }}" />
            </x-ui.field>
            <x-ui.field :label="__('Days to pay')" error="due_days" optional :hint="__('The due date each invoice states, counted from its issue date.')">
                <x-ui.input type="number" name="due_days" min="0" max="365" value="{{ old('due_days', $dueDays) }}" />
            </x-ui.field>
        </div>
        <div class="mt-4">
            <x-ui.switch name="auto_issue" value="1" off="0" :checked="(bool) old('auto_issue', false)" :description="__('Off: each invoice is made as a draft for you to check and issue.')">
                {{ __('Issue each invoice as it is made') }}
            </x-ui.switch>
        </div>
    </x-ui.section>

    <x-ui.section collapse :title="__('Terms')" :description="__('For a termly schedule: each term\'s first and last day. Each term is invoiced on its first day.')">
        <div class="space-y-3">
            @for ($row = 0; $row < 6; $row++)
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <x-ui.field :label="__('Term :number starts', ['number' => $row + 1])" error="terms.{{ $row }}.start_date" optional>
                        <x-ui.date-input name="terms[{{ $row }}][start_date]" value="{{ old('terms.'.$row.'.start_date') }}" />
                    </x-ui.field>
                    <x-ui.field :label="__('Term :number ends', ['number' => $row + 1])" error="terms.{{ $row }}.end_date" optional>
                        <x-ui.date-input name="terms[{{ $row }}][end_date]" value="{{ old('terms.'.$row.'.end_date') }}" />
                    </x-ui.field>
                </div>
            @endfor
        </div>
    </x-ui.section>

    <x-ui.section collapse :title="__('Instalments')" :description="__('Split the invoice\'s total into the number of periods above instead of billing it every period.')">
        <x-ui.field :label="__('Instalment plan')" error="instalment_model">
            <x-ui.select name="instalment_model">
                <option value="">{{ __('No: bill the whole invoice every period') }}</option>
                <option value="invoice" @selected(old('instalment_model') === 'invoice')>{{ __('Yes, each instalment a tax invoice - the supply is delivered') }}</option>
                @if ($prepaymentOffered)
                    <option value="prepayment" @selected(old('instalment_model') === 'prepayment')>{{ __('Yes, paid ahead - advances settled by a final invoice') }}</option>
                @endif
            </x-ui.select>
        </x-ui.field>
    </x-ui.section>

    <x-ui.button>{{ __('Save schedule') }}</x-ui.button>
</form>
@endsection
