{{--
    A period, as x-ui.list-filters draws it: a preset, or two dates of the
    reader's own. A date typed in counts as custom dates whatever the preset
    says, so nobody has to change two controls to ask one question. Given
    $filter and $list.
--}}
@php
    $value = $list->value($filter->name());
    $preset = $value['preset'] ?? '';
@endphp
<fieldset class="sm:col-span-2">
    <legend class="text-sm font-medium text-fg">{{ $filter->label() }}</legend>
    <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
        <x-ui.field :label="__('Period')">
            <x-ui.select name="{{ $filter->name() }}">
                <option value="">{{ __('Any time') }}</option>
                @foreach ($filter->presets() as $key => $label)
                    <option value="{{ $key }}" @selected($preset === $key)>{{ $label }}</option>
                @endforeach
            </x-ui.select>
        </x-ui.field>
        <x-ui.field :label="__('From')">
            <x-ui.date-input name="{{ $filter->fromKey() }}" value="{{ $preset === 'custom' ? $list->input($filter->fromKey()) : '' }}" />
        </x-ui.field>
        <x-ui.field :label="__('To')">
            <x-ui.date-input name="{{ $filter->toKey() }}" value="{{ $preset === 'custom' ? $list->input($filter->toKey()) : '' }}" />
        </x-ui.field>
    </div>
</fieldset>
