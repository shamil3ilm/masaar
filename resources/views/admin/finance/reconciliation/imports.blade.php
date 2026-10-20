@extends('layouts.admin')

@section('title', __('Reconciliation'))

@section('content')
<x-ui.page-header :title="__('Reconciliation')" :description="__('Bank statements and card settlement reports, matched line by line to what customers owe.')" />

@include('admin.finance.reconciliation.nav', ['current' => 'imports'])

@php($canChange = auth()->user()?->canAdmin('billing.manage'))

@if ($canChange)
    <x-ui.card class="mb-6" :title="__('Import a statement')" :description="__('A file is read whole and kept only when all of it is right. The same file, or a statement already imported, is refused; a line already imported from an overlapping statement is skipped.')">
        <form method="POST" action="{{ route('admin.finance.reconciliation.imports.store') }}" enctype="multipart/form-data" class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            @csrf
            <x-ui.field :label="__('What it is')" error="kind">
                <x-ui.select name="kind">
                    @foreach ($kinds as $value => $label)
                        <option value="{{ $value }}" @selected(old('kind') === $value)>{{ $label }}</option>
                    @endforeach
                </x-ui.select>
            </x-ui.field>
            <x-ui.field :label="__('Bank account')" error="bank_account" :hint="__('For a camt.053 or MT940 statement. A CSV is the account of its profile.')" optional>
                <x-ui.select name="bank_account">
                    <option value="">{{ __('Choose an account') }}</option>
                    @foreach ($accounts as $account)
                        <option value="{{ $account['key'] }}" @selected(old('bank_account') === $account['key'])>{{ $account['bank_name'] }} ({{ $account['currency'] }})</option>
                    @endforeach
                </x-ui.select>
            </x-ui.field>
            <x-ui.field :label="__('Statement profile')" error="profile" :hint="__('For a CSV: how its bank writes it. Profiles are kept in Finance settings.')" optional>
                <x-ui.select name="profile">
                    <option value="">{{ __('Choose a profile') }}</option>
                    @foreach ($profiles as $profile)
                        <option value="{{ $profile['key'] }}" @selected(old('profile') === $profile['key'])>{{ $profile['name'] }}</option>
                    @endforeach
                </x-ui.select>
            </x-ui.field>
            <x-ui.field :label="__('File')" error="file">
                <x-ui.file name="file" accept=".csv,.txt,.xml,.sta,.940" :max-kb="$maxKb" required />
            </x-ui.field>
            <div class="sm:col-span-2"><x-ui.button>{{ __('Import') }}</x-ui.button></div>
        </form>
    </x-ui.card>
@endif

<div class="bg-surface rounded-card border border-line shadow-card overflow-hidden">
    <x-ui.table :primary="[0, 1, 3]">
        <x-slot:head>
            <x-ui.th>{{ __('Imported') }}</x-ui.th>
            <x-ui.th>{{ __('Statement') }}</x-ui.th>
            <x-ui.th>{{ __('Period') }}</x-ui.th>
            <x-ui.th align="right">{{ __('New lines') }}</x-ui.th>
            <x-ui.th align="right">{{ __('Skipped') }}</x-ui.th>
            <x-ui.th>{{ __('File') }}</x-ui.th>
        </x-slot:head>
        @forelse ($imports as $import)
            <tr class="hover:bg-sunken">
                <td class="px-6 py-4 text-sm text-fg">{{ $import->created_at?->toDateString() }}
                    <span class="block text-xs text-muted">{{ $import->kind->label() }}</span>
                </td>
                <td class="px-6 py-4 text-sm text-fg" dir="ltr">{{ $import->statement_reference ?? '—' }}
                    <span class="block text-xs text-muted">{{ $import->currency }} {{ $import->account_identifier }}</span>
                </td>
                <td class="px-6 py-4 text-sm text-muted">{{ $import->period_from?->toDateString() }}{{ $import->period_to ? ' – '.$import->period_to->toDateString() : '' }}</td>
                <td class="px-6 py-4 text-right text-sm tabular-nums text-fg">{{ $import->lines_new }}</td>
                <td class="px-6 py-4 text-right text-sm tabular-nums text-muted">{{ $import->lines_duplicate }}</td>
                <td class="px-6 py-4 text-sm text-muted">{{ $import->file_name }}
                    @if ($import->file_removed_at)
                        <span class="block text-xs text-subtle">{{ __('File removed :date', ['date' => $import->file_removed_at->toDateString()]) }}</span>
                    @endif
                </td>
            </tr>
        @empty
            <x-ui.empty :columns="6">{{ __('No statement imported yet.') }}</x-ui.empty>
        @endforelse
    </x-ui.table>
</div>

{{ $imports->links() }}
@endsection
