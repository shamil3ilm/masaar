{{--
    The statement's currency and dates, asked for by GET so the page can be
    bookmarked and shared.

    action      the statement page's address
    statement   the statement shown
    currencies  the currencies the customer has used, its own first
--}}
<form method="GET" action="{{ $action }}" class="mb-6 flex flex-wrap items-end gap-4">
    @if (count($currencies) > 1)
        <x-ui.field :label="__('Currency')">
            <x-ui.select name="currency">
                @foreach ($currencies as $option)
                    <option value="{{ $option }}" @selected($option === $statement['currency'])>{{ $option }}</option>
                @endforeach
            </x-ui.select>
        </x-ui.field>
    @endif
    <x-ui.field :label="__('From')">
        <x-ui.date-input name="from" value="{{ $statement['from'] }}" />
    </x-ui.field>
    <x-ui.field :label="__('To')">
        <x-ui.date-input name="to" value="{{ $statement['to'] }}" />
    </x-ui.field>
    <x-ui.button variant="secondary">{{ __('Show') }}</x-ui.button>
</form>
