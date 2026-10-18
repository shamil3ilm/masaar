@props(['align' => 'left', 'list' => null, 'sort' => null])

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

    list and sort make the heading a link that orders a history list by its
    column (App\Http\Listing\ListState::sortUrl), the other way round on a
    second click; aria-sort says which way it is ordered now.
--}}
@php
    $alignment = $align === 'right' ? 'text-end' : 'text-start';
    $sortable = $list !== null && $sort !== null && isset($list->spec->sorts[$sort]);
    $direction = $sortable ? $list->sortedBy($sort) : null;
@endphp

<th scope="col"
    @if ($sortable) aria-sort="{{ match ($direction) { 'asc' => 'ascending', 'desc' => 'descending', default => 'none' } }}" @endif
    {{ $attributes->merge(['class' => 'px-6 py-3 text-xs font-semibold uppercase tracking-wider text-subtle '.$alignment]) }}>
    @if ($sortable)
        <a href="{{ $list->sortUrl($sort) }}" class="inline-flex min-h-11 items-center gap-1 hover:text-fg focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus">
            {{ $slot }}
            <svg class="size-3.5 {{ $direction === null ? 'opacity-40' : '' }} {{ $direction === 'asc' ? 'rotate-180' : '' }}" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 9l6 6 6-6" /></svg>
        </a>
    @else
        {{ $slot }}
    @endif
</th>
