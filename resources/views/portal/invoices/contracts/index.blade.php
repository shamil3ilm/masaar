@extends('layouts.portal')

@section('title', __('Contracts'))

{{--
    Contracts billed in stages, with retention withheld from each stage and
    released later (ProgressBilling), and the form to record one.

    @param \Illuminate\Support\Collection<int, \App\Domains\Invoice\Models\Contract> $contracts
--}}

@section('content')
<x-ui.page-header :title="__('Contracts')"
                  :description="__('Bill a contract stage by stage, withhold retention from each stage, and release it later on its own invoice.')">
    <x-slot:breadcrumb>
        <x-ui.breadcrumb :items="[['label' => __('Invoices'), 'href' => route('portal.invoices.index')], ['label' => __('Contracts')]]" />
    </x-slot:breadcrumb>
</x-ui.page-header>

<x-ui.card class="mb-6 overflow-hidden" flush>
    <x-ui.table :primary="[0, 1, 3]">
        <x-slot:head>
            <x-ui.th>{{ __('Reference') }}</x-ui.th>
            <x-ui.th>{{ __('Title') }}</x-ui.th>
            <x-ui.th>{{ __('Customer') }}</x-ui.th>
            <x-ui.th align="right">{{ __('Value before VAT') }}</x-ui.th>
            <x-ui.th align="right">{{ __('Retention') }}</x-ui.th>
        </x-slot:head>
        @forelse ($contracts as $contract)
            <tr class="hover:bg-sunken" data-contract>
                <td class="px-6 py-3 text-sm"><a href="{{ route('portal.contracts.show', $contract->id) }}" class="text-accent hover:underline" dir="ltr">{{ $contract->reference }}</a></td>
                <td class="px-6 py-3 text-sm text-fg">{{ $contract->title }}</td>
                <td class="px-6 py-3 text-sm">{{ $contract->customer?->name }}</td>
                <td class="px-6 py-3 text-end text-sm"><x-ui.amount :value="$contract->value" /></td>
                <td class="px-6 py-3 text-end text-sm" dir="ltr">{{ (float) $contract->retention_percent }}%</td>
            </tr>
        @empty
            <x-ui.empty :columns="5">{{ __('No contracts yet.') }}</x-ui.empty>
        @endforelse
    </x-ui.table>
</x-ui.card>

<x-ui.card :title="__('New contract')" :description="__('Its value and stages before VAT. A stage is an amount or a percentage of the value.')">
    <form method="POST" action="{{ route('portal.contracts.store') }}" class="space-y-4" data-contract-form>
        @csrf
        <div class="grid gap-4 sm:grid-cols-2">
            <x-ui.field :label="__('Customer')" error="customer_id">
                <x-ui.select name="customer_id" :source="route('portal.customers.lookup')" required>
                    <option value="">{{ __('Search customers') }}</option>
                </x-ui.select>
            </x-ui.field>
            <x-ui.field :label="__('Reference')" :hint="__('The contract number: every invoice names it.')" error="reference">
                <x-ui.input name="reference" value="{{ old('reference') }}" maxlength="127" required dir="ltr" />
            </x-ui.field>
            <x-ui.field :label="__('Title')" error="title">
                <x-ui.input name="title" value="{{ old('title') }}" maxlength="200" required />
            </x-ui.field>
            <x-ui.field :label="__('Title in Arabic')" error="title_ar" optional>
                <x-ui.input name="title_ar" value="{{ old('title_ar') }}" maxlength="200" dir="rtl" />
            </x-ui.field>
            <x-ui.field :label="__('Value before VAT')" error="value">
                <x-ui.input type="number" step="0.01" min="0.01" name="value" value="{{ old('value') }}" required />
            </x-ui.field>
            <x-ui.field :label="__('Retention %')" :hint="__('Withheld from each stage until released.')" error="retention_percent" optional>
                <x-ui.input type="number" step="0.01" min="0" max="50" name="retention_percent" value="{{ old('retention_percent', '0') }}" />
            </x-ui.field>
        </div>
        @foreach (range(0, 4) as $index)
            <div class="grid gap-3 sm:grid-cols-2" data-stage-row>
                <x-ui.field :label="__('Stage :number', ['number' => $index + 1])" error="stages.{{ $index }}.name" :optional="$index > 0">
                    <x-ui.input name="stages[{{ $index }}][name]" value="{{ old('stages.'.$index.'.name') }}" maxlength="150" />
                </x-ui.field>
                <x-ui.field :label="__('Stage name in Arabic')" error="stages.{{ $index }}.name_ar" optional>
                    <x-ui.input name="stages[{{ $index }}][name_ar]" value="{{ old('stages.'.$index.'.name_ar') }}" maxlength="150" dir="rtl" />
                </x-ui.field>
                <x-ui.field :label="__('Percentage of the value')" error="stages.{{ $index }}.percent" optional>
                    <x-ui.input type="number" step="0.0001" min="0" max="100" name="stages[{{ $index }}][percent]" value="{{ old('stages.'.$index.'.percent') }}" />
                </x-ui.field>
                <x-ui.field :label="__('Or an amount before VAT')" error="stages.{{ $index }}.amount" optional>
                    <x-ui.input type="number" step="0.01" min="0" name="stages[{{ $index }}][amount]" value="{{ old('stages.'.$index.'.amount') }}" />
                </x-ui.field>
            </div>
        @endforeach
        <x-ui.button>{{ __('Record the contract') }}</x-ui.button>
    </form>
</x-ui.card>
@endsection
