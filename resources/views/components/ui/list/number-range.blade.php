{{--
    Two amounts a list's rows fall between, as x-ui.list-filters draws them.
    Either may be left empty. Given $filter and $list.
--}}
<fieldset class="sm:col-span-2">
    <legend class="text-sm font-medium text-fg">{{ $filter->label() }}</legend>
    <div class="grid grid-cols-2 gap-3">
        <x-ui.field :label="__('From')">
            <x-ui.input type="number" name="{{ $filter->minKey() }}" value="{{ $list->input($filter->minKey()) }}" step="0.01" inputmode="decimal" />
        </x-ui.field>
        <x-ui.field :label="__('To')">
            <x-ui.input type="number" name="{{ $filter->maxKey() }}" value="{{ $list->input($filter->maxKey()) }}" step="0.01" inputmode="decimal" />
        </x-ui.field>
    </div>
</fieldset>
