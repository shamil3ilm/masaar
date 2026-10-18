{{-- A list's text search, as x-ui.list-filters draws it. Given $filter and $list. --}}
<x-ui.field :label="$filter->label()">
    <x-ui.input type="search" name="{{ $filter->name() }}" value="{{ $list->input($filter->name()) }}" maxlength="100"
                placeholder="{{ $filter->placeholder() }}" />
</x-ui.field>
