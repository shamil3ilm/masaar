@props(['tone' => 'info', 'title' => null])

{{--
    A message about the page, in the tone of what it says.

      success  it worked
      warning  it worked, and something needs reading - the action succeeded
               but the outcome is partial
      danger   it did not work, or it cannot
      info     something to know, nothing wrong

    Danger is announced at once (role="alert"); the others politely
    (role="status"), so a screen reader is not interrupted mid-sentence to be
    told something saved. The icon repeats the tone in shape as well as
    colour, for anyone who cannot tell amber from red.
--}}
@php
    $look = match ($tone) {
        'success' => 'border-success-line bg-success-soft text-success',
        'warning' => 'border-warning-line bg-warning-soft text-warning',
        'danger' => 'border-danger-line bg-danger-soft text-danger',
        default => 'border-info-line bg-info-soft text-info',
    };

    $icon = match ($tone) {
        'success' => 'M5 12.5l4.5 4.5L19 7.5',
        'warning' => 'M12 8v5m0 3.5h.01M10.3 3.9L2.6 17.5A2 2 0 004.3 20.5h15.4a2 2 0 001.7-3L13.7 3.9a2 2 0 00-3.4 0z',
        'danger' => 'M12 8v5m0 3.5h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z',
        default => 'M12 11v5m0-8.5h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z',
    };
@endphp

<div role="{{ $tone === 'danger' ? 'alert' : 'status' }}" {{ $attributes->merge(['class' => 'flex gap-3 rounded-tile border px-4 py-3 text-sm '.$look]) }}>
    <svg class="mt-0.5 size-5 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
        <path stroke-linecap="round" stroke-linejoin="round" d="{{ $icon }}" />
    </svg>
    <div class="min-w-0 flex-1">
        @if ($title)
            <p class="font-semibold">{{ $title }}</p>
        @endif
        <div @class(['mt-0.5' => $title])>{{ $slot }}</div>
    </div>
</div>
