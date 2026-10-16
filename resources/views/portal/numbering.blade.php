@extends('layouts.portal')

@section('title', __('Numbering'))

@section('content')
<div class="mb-6">
    <h2 class="text-2xl font-bold text-gray-800 dark:text-gray-100">{{ __('Numbering') }}</h2>
    <p class="text-gray-500 dark:text-gray-400">
        {{ __('How documents are numbered when they are issued without a number of their own. Numbers are given at issue, so issued documents run without gaps.') }}
    </p>
    <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
        {{ __('Tokens:') }} <code>{YYYY}</code> {{ __('year,') }} <code>{YY}</code> {{ __('two-digit year,') }} <code>{MM}</code> {{ __('month,') }}
        <code>{SEQ}</code> {{ __('the counter, padded with zeros to the width you choose.') }}
    </p>
</div>

@php
    $labels = [
        'invoice' => __('Invoices'),
        'credit_note' => __('Credit notes'),
        'debit_note' => __('Debit notes'),
        'prepayment' => __('Prepayment invoices'),
    ];
@endphp

<div class="max-w-2xl space-y-6">
    @foreach ($sequences as $sequence)
        @php($type = $sequence['document_type'])
        @php($bag = $errors->getBag($type))
        <form method="POST" action="{{ route('portal.numbering.update', $type) }}" class="rounded-lg bg-white dark:bg-gray-900 shadow">
            @csrf
            <div class="flex items-center justify-between border-b p-4">
                <h3 class="font-semibold text-gray-800 dark:text-gray-100">{{ $labels[$type] ?? $type }}</h3>
                <span class="text-sm text-gray-500 dark:text-gray-400">{{ __('Next:') }} <span class="font-mono text-gray-800 dark:text-gray-100" dir="ltr">{{ $sequence['next'] }}</span></span>
            </div>

            @if ($bag->any())
                <div class="mx-4 mt-4 rounded border border-red-200 dark:border-red-800 bg-red-50 dark:bg-red-950/40 px-4 py-3 text-sm text-red-700 dark:text-red-300">
                    <ul class="list-disc ps-5">
                        @foreach ($bag->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <div class="grid grid-cols-1 gap-4 p-4 sm:grid-cols-2">
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

                <x-ui.field :label="__('Start the counter at')">
                    <x-ui.input type="number" name="next_number" min="1" placeholder="{{ __('Unchanged') }}" />
                    <span class="mt-1 block text-xs font-normal text-gray-500 dark:text-gray-400">{{ __('To continue from a previous system\'s numbers.') }}</span>
                </x-ui.field>
            </div>

            <div class="border-t p-4">
                <x-ui.button variant="primary">{{ __('Save') }}</x-ui.button>
            </div>
        </form>
    @endforeach
</div>
@endsection
