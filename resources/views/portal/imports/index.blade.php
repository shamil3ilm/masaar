@extends('layouts.portal')

@section('title', __('Import'))

@php
    $zone = \App\Support\CountryClock::timeZone($organization->country);
@endphp

@section('content')
<x-ui.page-header :title="__('Import')"
                  :description="__('Bring in invoices, customers or products from a CSV file. Each row is checked first and shown with its problems; nothing is saved until you confirm, and invoices are saved as drafts, never issued.')" />

<div class="max-w-5xl space-y-6">
    <form method="POST" action="{{ route('portal.imports.store') }}" enctype="multipart/form-data" class="rounded-card border border-line bg-surface shadow-card" data-import-form>
        @csrf
        <div class="border-b border-line p-5">
            <h2 class="text-base font-semibold text-fg">{{ __('Check a file') }}</h2>
            <p class="mt-0.5 text-sm text-subtle">{{ __('Start from the template for what the file holds: its columns are your country\'s.') }}</p>
        </div>
        <div class="space-y-4 p-5">
            <fieldset>
                <legend class="text-sm font-medium text-fg">{{ __('The file holds') }}</legend>
                <div class="mt-2 grid grid-cols-1 gap-3 sm:grid-cols-3">
                    @foreach ($kinds as $kind => $about)
                        <x-ui.radio name="kind" value="{{ $kind }}" tile :checked="old('kind', 'invoices') === $kind">{{ $about['label'] }}</x-ui.radio>
                    @endforeach
                </div>
            </fieldset>
            <x-ui.field :label="__('CSV file')" error="file">
                <x-ui.file name="file" accept=".csv,text/csv" :max-kb="(int) config('imports.max_kilobytes')" required />
            </x-ui.field>
        </div>
        <div class="border-t border-line p-5">
            <x-ui.button variant="primary">{{ __('Check the file') }}</x-ui.button>
        </div>
    </form>

    <x-ui.card :title="__('Templates')" :description="__('One row a record, saved from your spreadsheet as CSV UTF-8. In an invoice file each row is one line: rows with the same invoice_number are one invoice, and line. starts a line\'s own columns.')">
        <div class="divide-y divide-line">
            @foreach ($kinds as $kind => $about)
                <div class="flex flex-wrap items-start justify-between gap-3 py-3" data-import-template="{{ $kind }}">
                    <div class="min-w-0 flex-1">
                        <p class="font-medium text-fg">{{ $about['label'] }}</p>
                        <x-ui.disclosure :summary="__(':count columns, :required required', ['count' => count($about['columns']), 'required' => count($about['required'])])" class="border-b-0 py-1">
                            <ul class="mt-2 flex flex-wrap gap-x-4 gap-y-1 font-mono text-xs text-muted" dir="ltr">
                                @foreach ($about['columns'] as $column)
                                    <li @class(['font-semibold text-fg' => in_array($column, $about['required'], true)])>{{ $column }}{{ in_array($column, $about['required'], true) ? ' *' : '' }}</li>
                                @endforeach
                            </ul>
                        </x-ui.disclosure>
                    </div>
                    <x-ui.button variant="secondary" size="sm" :href="route('portal.imports.template', $kind)">{{ __('Download the template') }}</x-ui.button>
                </div>
            @endforeach
        </div>
    </x-ui.card>

    <x-ui.card :title="__('Recent imports')" flush>
        <x-ui.table :primary="[1, 4]">
            <x-slot:head>
                <x-ui.th>{{ __('When') }}</x-ui.th>
                <x-ui.th>{{ __('File') }}</x-ui.th>
                <x-ui.th>{{ __('Holds') }}</x-ui.th>
                <x-ui.th>{{ __('Rows') }}</x-ui.th>
                <x-ui.th>{{ __('Status') }}</x-ui.th>
                <x-ui.th></x-ui.th>
            </x-slot:head>
            @forelse ($imports as $import)
                <tr data-import="{{ $import->id }}">
                    <td class="px-4 py-3 text-xs text-muted whitespace-nowrap"><x-ui.date :value="$import->created_at" time :zone="$zone" /></td>
                    <td class="px-4 py-3 text-sm text-fg break-all" dir="ltr">{{ $import->file_name }}</td>
                    <td class="px-4 py-3 text-sm text-muted">{{ \App\Domains\Invoice\Imports\ImportColumns::label($import->kind) }}</td>
                    <td class="px-4 py-3 text-xs text-muted tabular-nums">{{ __(':valid passed, :invalid with problems', ['valid' => $import->rows_valid, 'invalid' => $import->rows_invalid]) }}</td>
                    <td class="px-4 py-3 text-sm">@include('portal.imports.partials.status', ['import' => $import])</td>
                    <td class="px-4 py-3 text-end">
                        <x-ui.button variant="secondary" size="sm" :href="route('portal.imports.show', $import->id)">{{ __('Open') }}</x-ui.button>
                    </td>
                </tr>
            @empty
                <x-ui.empty :columns="6">{{ __('No files imported yet.') }}</x-ui.empty>
            @endforelse
        </x-ui.table>
    </x-ui.card>
</div>
@endsection
