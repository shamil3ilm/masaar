@props(['description' => null, 'tile' => false])

{{--
    One answer of several.

    The browser's own radio, coloured by accent-color, inside its label so the
    words are part of the target. Arrow keys move through a group as they do
    for any radio group; nothing here gets in the way of that.

    tile draws it as a bordered card, outlined when chosen - for a choice that
    matters enough to read, such as where a company is registered.
--}}
@php
    $shell = $tile
        ? 'flex min-h-14 cursor-pointer items-start gap-3 rounded-tile border border-line-strong bg-surface p-4 text-sm text-fg transition-colors'
            .' hover:border-subtle has-checked:border-accent has-checked:bg-accent-soft has-checked:ring-1 has-checked:ring-accent'
        : 'inline-flex min-h-11 cursor-pointer items-start gap-3 py-2.5 text-sm text-fg';
@endphp

<label class="{{ $shell }} has-disabled:cursor-not-allowed has-disabled:opacity-60">
    <input type="radio" {{ $attributes->merge(['class' => 'mt-px size-5 shrink-0 cursor-pointer accent-action']) }}>
    <span>
        <span class="font-medium">{{ $slot }}</span>
        @if ($description)
            <span class="mt-0.5 block text-xs font-normal text-subtle">{{ $description }}</span>
        @endif
    </span>
</label>
