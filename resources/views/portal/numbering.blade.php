@extends('layouts.portal')

@section('title', __('Numbering'))

{{--
    @param list<array<string, mixed>> $sequences  NumberingSettings::sequences
    @param bool $shared  every branch numbers from the organization's series
    @param list<array{id: string, name: string, till_number: int|null}> $branches  BranchService::tills
    @param string|null $branchId  the till whose series is shown, or null
--}}

@section('content')
<x-ui.page-header :title="__('Numbering')"
                  :description="__('How documents are numbered when they are issued without a number of their own. Numbers are given at issue, so issued documents run without gaps.')" />

<p class="-mt-3 mb-6 text-sm text-muted">
    {{ __('Tokens:') }} <code>{YYYY}</code> {{ __('year,') }} <code>{YY}</code> {{ __('two-digit year,') }} <code>{MM}</code> {{ __('month,') }}
    <code>{SEQ}</code> {{ __('the counter, padded with zeros to the width you choose.') }}
</p>

@php
    $labels = [
        'invoice' => __('Invoices'),
        'credit_note' => __('Credit notes'),
        'debit_note' => __('Debit notes'),
        'prepayment' => __('Prepayment invoices'),
    ];
@endphp

<div class="max-w-2xl space-y-6">
    {{-- Each till its own series, so two tills never wait for each other
         for a number; or one run of numbers for the whole organization. --}}
    <form method="POST" action="{{ route('portal.numbering.mode') }}">
        @csrf
        <x-ui.section :title="__('Series')" :description="__('Each till numbers from a series of its own unless you choose one series for the whole organization.')">
            <div class="space-y-1">
                <x-ui.radio name="shared_numbering" value="0" :checked="! $shared" :description="__('Recommended. A till\'s numbers carry its till number, such as INV-T01-2026-00001, and tills never wait for each other.')">
                    {{ __('Each till its own series') }}
                </x-ui.radio>
                <x-ui.radio name="shared_numbering" value="1" :checked="$shared" :description="__('One run of numbers across every branch. Tills take turns for the next number.')">
                    {{ __('One series for the whole organization') }}
                </x-ui.radio>
            </div>
            <x-ui.button variant="secondary" class="mt-4">{{ __('Save') }}</x-ui.button>
        </x-ui.section>
    </form>

    @if ($branches !== [] && ! $shared)
        <form method="GET" action="{{ route('portal.numbering.edit') }}" class="flex flex-wrap items-end gap-3">
            <x-ui.field :label="__('Series of')">
                <x-ui.select name="branch">
                    <option value="">{{ __('The organization (head office)') }}</option>
                    @foreach ($branches as $branch)
                        <option value="{{ $branch['id'] }}" @selected($branchId === $branch['id'])>{{ __('Till :number - :name', ['number' => $branch['till_number'], 'name' => $branch['name']]) }}</option>
                    @endforeach
                </x-ui.select>
            </x-ui.field>
            <x-ui.button variant="secondary">{{ __('Show') }}</x-ui.button>
        </form>
    @endif

    @foreach ($sequences as $sequence)
        @php($type = $sequence['document_type'])
        @php($bag = $errors->getBag($type))
        <form method="POST" action="{{ route('portal.numbering.update', $type) }}">
            @csrf
            @if ($branchId)
                <input type="hidden" name="branch_id" value="{{ $branchId }}">
            @endif
            <x-ui.card :title="$labels[$type] ?? $type">
                <x-slot:actions>
                    <span class="text-sm text-muted">{{ __('Next:') }} <span class="font-mono text-fg" dir="ltr">{{ $sequence['next'] }}</span></span>
                </x-slot:actions>

                @if ($bag->any())
                    <x-ui.alert tone="danger" class="mb-4">
                        <ul class="list-disc ps-5">
                            @foreach ($bag->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </x-ui.alert>
                @endif

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <x-ui.field :label="__('Pattern')">
                        <x-ui.input type="text" name="pattern" maxlength="60" required class="font-mono" dir="ltr"
                                    value="{{ $bag->any() ? old('pattern') : $sequence['pattern'] }}" />
                    </x-ui.field>

                    <x-ui.field :label="__('Counter digits')">
                        <x-ui.input type="number" name="padding" min="1" max="10" required
                                    value="{{ $bag->any() ? old('padding') : $sequence['padding'] }}" />
                    </x-ui.field>

                    <x-ui.field :label="__('Restart the counter')">
                        <x-ui.select name="reset">
                            @foreach (['yearly' => __('Each year'), 'monthly' => __('Each month'), 'never' => __('Never')] as $value => $label)
                                <option value="{{ $value }}" @selected(($bag->any() ? old('reset') : $sequence['reset']) === $value)>{{ $label }}</option>
                            @endforeach
                        </x-ui.select>
                    </x-ui.field>

                    <x-ui.field :label="__('Start the counter at')" :hint="__('To continue from a previous system\'s numbers.')">
                        <x-ui.input type="number" name="next_number" min="1" placeholder="{{ __('Unchanged') }}" />
                    </x-ui.field>
                </div>

                <x-ui.button variant="primary" class="mt-4">{{ __('Save') }}</x-ui.button>
            </x-ui.card>
        </form>
    @endforeach
</div>
@endsection
