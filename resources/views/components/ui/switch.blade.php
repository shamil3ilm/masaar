@props(['description' => null, 'off' => null])

{{--
    On or off, for a setting that takes effect as a whole.

    A checkbox underneath, announced as a switch (role="switch") so a screen
    reader says "on" and "off" rather than "checked". The input is visually
    hidden but still focused and toggled by the keyboard; the track is drawn
    from its state with peer-*, so nothing here needs script.

    off: the value sent while it is off. An unticked checkbox sends nothing at
    all, so a form that must hear "off" gets a hidden field of the same name
    before it.

    The thumb travels toward the end edge: right in English, left in Arabic.
--}}
@php
    $name = $attributes->get('name');
@endphp

<label class="inline-flex min-h-11 cursor-pointer items-center gap-3 text-sm text-fg has-disabled:cursor-not-allowed has-disabled:opacity-60">
    @if ($off !== null && $name)
        <input type="hidden" name="{{ $name }}" value="{{ $off }}">
    @endif
    <input type="checkbox" role="switch" {{ $attributes->merge(['class' => 'peer sr-only']) }}>
    <span aria-hidden="true"
          class="relative inline-flex h-6 w-11 shrink-0 items-center rounded-full bg-line-strong transition-colors peer-checked:bg-action peer-focus-visible:outline-2 peer-focus-visible:outline-offset-2 peer-focus-visible:outline-focus after:absolute after:start-0.5 after:size-5 after:rounded-full after:bg-white dark:after:bg-fg after:shadow-card after:transition-transform peer-checked:after:translate-x-5 rtl:peer-checked:after:-translate-x-5"></span>
    <span>
        <span class="font-medium">{{ $slot }}</span>
        @if ($description)
            <span class="mt-0.5 block text-xs font-normal text-subtle">{{ $description }}</span>
        @endif
    </span>
</label>
