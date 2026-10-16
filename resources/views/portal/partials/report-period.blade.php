{{--
    The period a report covers, and the usual ones a click away.

    @param \Carbon\CarbonImmutable $from
    @param \Carbon\CarbonImmutable $to
    @param array<string, array{from: string, to: string}> $presets
--}}
<form method="GET" action="{{ route('portal.reports') }}" class="mb-4 bg-white dark:bg-gray-900 rounded-lg shadow p-4 flex flex-wrap items-end gap-4">
    <div>
        <label for="from" class="block text-xs font-medium text-gray-500 dark:text-gray-400 uppercase mb-1">{{ __('From') }}</label>
        <x-ui.input id="from" name="from" type="date" value="{{ $from->toDateString() }}" />
    </div>
    <div>
        <label for="to" class="block text-xs font-medium text-gray-500 dark:text-gray-400 uppercase mb-1">{{ __('To') }}</label>
        <x-ui.input id="to" name="to" type="date" value="{{ $to->toDateString() }}" />
    </div>
    <x-ui.button variant="secondary">{{ __('Show') }}</x-ui.button>
</form>

<p class="mb-6 flex flex-wrap gap-3 text-sm">
    @foreach ($presets as $label => $range)
        <a href="{{ route('portal.reports', $range) }}" class="text-teal-700 dark:text-teal-300 hover:underline">{{ $label }}</a>
    @endforeach
</p>
