@props(['description' => null])

{{--
    A box to tick, with its words beside it.

    The browser's own checkbox, coloured by accent-color rather than redrawn:
    a redrawn one has to re-implement checked, indeterminate, disabled, high
    contrast and focus, and the native one already does all five. The label
    wraps it, so the words are part of the target and the row is 44px tall.

    Attributes go to the input - name, value, checked, required, disabled.
    The slot is the label; description is a line of help under it.
--}}
<label class="inline-flex min-h-11 cursor-pointer items-start gap-3 py-2.5 text-sm text-fg has-disabled:cursor-not-allowed has-disabled:opacity-60">
    <input type="checkbox" {{ $attributes->merge(['class' => 'mt-px size-5 shrink-0 cursor-pointer accent-action disabled:cursor-not-allowed']) }}>
    <span>
        <span class="font-medium">{{ $slot }}</span>
        @if ($description)
            <span class="mt-0.5 block text-xs font-normal text-subtle">{{ $description }}</span>
        @endif
    </span>
</label>
