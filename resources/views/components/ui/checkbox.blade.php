@props(['description' => null])

{{--
    A box to tick, with its words beside it.

    The browser's own checkbox, coloured by accent-color rather than redrawn:
    a redrawn one has to re-implement checked, indeterminate, disabled, high
    contrast and focus, and the native one already does all five.

    Attributes go to the input - name, value, checked, required, disabled.
    The slot is the label; description is a line of help under it.
--}}
@php
    $align = $description ? 'items-start pt-1 pb-1' : 'items-center py-1';
@endphp
<label class="inline-flex {{ $align }} cursor-pointer gap-2.5 text-sm text-fg has-disabled:cursor-not-allowed has-disabled:opacity-60">
    <input type="checkbox" {{ $attributes->merge(['class' => 'size-4 shrink-0 cursor-pointer rounded border-line-strong accent-action disabled:cursor-not-allowed focus-visible:outline-2 focus-visible:outline-focus']) }}>
    <span>
        <span class="font-medium leading-none">{{ $slot }}</span>
        @if ($description)
            <span class="mt-1 block text-xs font-normal text-subtle">{{ $description }}</span>
        @endif
    </span>
</label>
