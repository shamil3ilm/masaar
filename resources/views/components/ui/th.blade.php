@props(['align' => 'left'])

{{--
    A column heading that says it is one.

    Seventy-six of them were written by hand and not one carried scope, so a
    screen reader announcing a cell could not name the column it belonged to.
    On a submissions table that is the difference between "rejected" and
    "State: rejected".

    Eight different class strings said the same thing in four paddings. This
    is px-6 py-3, which sixty of the seventy-six already used.

    Both alignments are written out rather than composed, because Tailwind
    finds its classes by reading the source: a class built as 'text-'.$align
    appears nowhere for it to find and is left out of the stylesheet.
--}}
@php
    $alignment = $align === 'right' ? 'text-end' : 'text-start';
@endphp

<th scope="col" {{ $attributes->merge(['class' => 'px-6 py-3 text-xs font-semibold uppercase tracking-wider text-subtle '.$alignment]) }}>
    {{ $slot }}
</th>
