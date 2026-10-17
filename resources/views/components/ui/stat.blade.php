@props(['label', 'value', 'tone' => null, 'href' => null])

{{--
    One figure on a dashboard: a count, a total, a number of days.

    The figure is set in tabular numerals so a row of them lines up, and is
    left to right in Arabic too - a number reads the same either way, but its
    separators and a trailing currency code do not.

    tone   success, warning or danger to colour the figure by what it means;
           neutral otherwise.
    href   makes the whole tile a link to the list behind the figure.
--}}
@php
    $figure = match ($tone) {
        'success' => 'text-success',
        'warning' => 'text-warning',
        'danger' => 'text-danger',
        default => 'text-fg',
    };
    $tag = $href ? 'a' : 'div';
@endphp

<{{ $tag }} @if ($href) href="{{ $href }}" @endif
    {{ $attributes->merge(['class' => 'block rounded-card border border-line bg-surface p-5 shadow-card'.($href ? ' transition-colors hover:border-line-strong focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus' : '')]) }}>
    <p class="text-sm font-medium text-subtle">{{ $label }}</p>
    <p class="mt-2 text-3xl font-semibold tracking-tight tabular-nums {{ $figure }}" dir="ltr">{{ $value }}</p>
    @if (! $slot->isEmpty())
        <div class="mt-1 text-xs text-subtle">{{ $slot }}</div>
    @endif
</{{ $tag }}>
