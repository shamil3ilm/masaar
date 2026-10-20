{{-- An import's status, in the tone it reads in. --}}
@php
    [$tone, $label] = match ($import->status) {
        \App\Domains\Invoice\Models\CsvImport::CHECKING => ['info', __('Checking')],
        \App\Domains\Invoice\Models\CsvImport::CHECKED => [$import->rows_valid > 0 ? 'warning' : 'danger', $import->rows_valid > 0 ? __('Checked, not saved') : __('Nothing to save')],
        \App\Domains\Invoice\Models\CsvImport::IMPORTING => ['info', __('Saving')],
        \App\Domains\Invoice\Models\CsvImport::IMPORTED => ['success', __('Saved')],
        default => ['danger', __('Could not be read')],
    };
@endphp
<x-ui.badge :tone="$tone" :label="$label" />
