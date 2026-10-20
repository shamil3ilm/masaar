@extends('layouts.portal')

@section('title', __('Import'))

@php
    $kind = \App\Domains\Invoice\Imports\ImportColumns::label($import->kind);
    $preview = (array) $import->preview;
    $columns = \App\Domains\Invoice\Imports\ImportRows::previewColumns($import->kind);
    $problems = (array) $import->problems;
@endphp

@section('content')
<x-ui.page-header :title="$import->file_name">
    <x-slot:breadcrumb>
        <x-ui.breadcrumb :items="[['label' => __('Import'), 'href' => route('portal.imports.index')], ['label' => $kind]]" />
    </x-slot:breadcrumb>
    <x-slot:meta>@include('portal.imports.partials.status', ['import' => $import])</x-slot:meta>
</x-ui.page-header>

<div class="max-w-5xl space-y-6">
    @if ($import->busy())
        <x-ui.alert tone="info" :title="$import->status === \App\Domains\Invoice\Models\CsvImport::CHECKING ? __('Checking the file') : __('Saving the rows that passed')">
            <p>{{ __('A large file takes a little while. Open this page again to see where it stands.') }}</p>
            <div class="mt-3"><x-ui.button variant="secondary" size="sm" :href="route('portal.imports.show', $import->id)">{{ __('Look again') }}</x-ui.button></div>
        </x-ui.alert>
    @elseif ($import->status === \App\Domains\Invoice\Models\CsvImport::FAILED)
        <x-ui.alert tone="danger" :title="__('The file could not be used')">{{ $import->failure }}</x-ui.alert>
    @endif

    <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
        <x-ui.stat :label="__('Rows')" :value="$import->rows_total" />
        <x-ui.stat :label="__('Passed')" :value="$import->rows_valid" :tone="$import->rows_valid > 0 ? 'success' : null" />
        <x-ui.stat :label="__('With problems')" :value="$import->rows_invalid" :tone="$import->rows_invalid > 0 ? 'danger' : null" />
        <x-ui.stat :label="__('Saved')" :value="$import->rows_imported" />
    </div>

    @if ($import->canImport())
        <form method="POST" action="{{ route('portal.imports.confirm', $import->id) }}" class="rounded-card border border-line bg-surface p-5 shadow-card"
              data-confirm-action="{{ __('Save') }}"
              data-confirm="{{ __('Save the :count rows that passed?', ['count' => $import->rows_valid]) }}"
              data-confirm-detail="{{ __('Rows with problems are left out. Invoices are saved as drafts: none is issued or filed.') }}">
            @csrf
            <p class="text-sm text-muted">{{ $import->rows_invalid > 0
                ? __('The rows with problems are left out: fix them in the file and import it again for those.')
                : __('Every row passed.') }}</p>
            <div class="mt-3"><x-ui.button variant="primary">{{ __('Save the :count rows that passed', ['count' => $import->rows_valid]) }}</x-ui.button></div>
        </form>
    @elseif ($import->status === \App\Domains\Invoice\Models\CsvImport::IMPORTED)
        <x-ui.alert tone="success" :title="__('Saved')">
            {{ $import->kind === \App\Domains\Invoice\Imports\ImportColumns::INVOICES
                ? __(':count invoices saved as drafts. Review and issue them from the invoice list.', ['count' => $import->rows_imported])
                : __(':count records saved.', ['count' => $import->rows_imported]) }}
        </x-ui.alert>
    @endif

    @if ($problems !== [])
        <x-ui.card :title="__('Problems')" :description="$import->rows_invalid > count($problems) ? __('The first :count problems are shown.', ['count' => count($problems)]) : null" flush>
            <x-ui.table :primary="[0, 2]">
                <x-slot:head>
                    <x-ui.th>{{ __('Row') }}</x-ui.th>
                    <x-ui.th>{{ __('Column') }}</x-ui.th>
                    <x-ui.th>{{ __('Problem') }}</x-ui.th>
                </x-slot:head>
                @foreach ($problems as $problem)
                    <tr class="align-top" data-import-problem>
                        <td class="px-4 py-3 text-sm text-fg tabular-nums">{{ $problem['row'] }}</td>
                        <td class="px-4 py-3 font-mono text-xs text-muted" dir="ltr">{{ $problem['field'] }}</td>
                        <td class="px-4 py-3 text-sm text-fg">{{ $problem['message'] }}</td>
                    </tr>
                @endforeach
            </x-ui.table>
        </x-ui.card>
    @endif

    @if ($preview !== [])
        <x-ui.card :title="__('What would be saved')" :description="__('The first records from the rows that passed.')" flush>
            <x-ui.table :primary="[0, 1]">
                <x-slot:head>
                    @foreach ($columns as $column)
                        <x-ui.th>{{ $column === 'row' ? __('Row') : ($column === 'lines' ? __('Lines') : $column) }}</x-ui.th>
                    @endforeach
                </x-slot:head>
                @foreach ($preview as $record)
                    <tr data-import-preview>
                        @foreach ($columns as $column)
                            <td class="px-4 py-3 text-sm text-fg">{{ $record[$column] ?? '' }}</td>
                        @endforeach
                    </tr>
                @endforeach
            </x-ui.table>
        </x-ui.card>
    @endif
</div>
@endsection
