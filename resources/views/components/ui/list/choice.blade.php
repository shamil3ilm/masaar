{{-- One of a list's choices, as x-ui.list-filters draws it. Given $filter and $list. --}}
<x-ui.field :label="$filter->label()">
    <x-ui.select name="{{ $filter->name() }}">
        <option value="">{{ $filter->anyLabel() }}</option>
        @foreach ($filter->options() as $value => $label)
            <option value="{{ $value }}" @selected($list->input($filter->name()) === (string) $value)>{{ $label }}</option>
        @endforeach
    </x-ui.select>
</x-ui.field>
