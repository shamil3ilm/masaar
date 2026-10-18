{{--
    The period a report covers, and the usual ones a click away.

    @param \Carbon\CarbonImmutable $from
    @param \Carbon\CarbonImmutable $to
    @param array<string, array{from: string, to: string}> $presets
    @param string $periodRoute  the page the period is for; the reports page when not given
--}}
@php($periodRoute ??= 'portal.reports')
<form method="GET" action="{{ route($periodRoute) }}" class="mb-4 bg-surface rounded-card border border-line shadow-card p-4 flex flex-wrap items-end gap-4">
    <div>
        <label for="from" class="block text-xs font-medium text-muted uppercase mb-1">{{ __('From') }}</label>
        <x-ui.input id="from" name="from" type="date" value="{{ $from->toDateString() }}" />
    </div>
    <div>
        <label for="to" class="block text-xs font-medium text-muted uppercase mb-1">{{ __('To') }}</label>
        <x-ui.input id="to" name="to" type="date" value="{{ $to->toDateString() }}" />
    </div>
    <x-ui.button variant="secondary">{{ __('Show') }}</x-ui.button>
</form>

<p class="mb-6 flex flex-wrap gap-3 text-sm">
    @foreach ($presets as $label => $range)
        <a href="{{ route($periodRoute, $range) }}" class="text-accent hover:underline">{{ $label }}</a>
    @endforeach
</p>
