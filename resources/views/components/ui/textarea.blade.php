@props(['rows' => 3])

{{--
    A longer piece of text - a note, a reason.

    Here for the same reason as x-ui.input and x-ui.select: one border, one
    radius, one focus ring. min-h-11 is not applied, because a textarea's
    height is its rows and the smallest of those is already taller than a
    touch target.
--}}
<textarea rows="{{ $rows }}" {{ $attributes->merge([
    'class' => 'mt-1 block w-full rounded-control border border-line-strong bg-surface px-3 py-2 text-sm font-normal text-fg shadow-card'
        .' placeholder:text-subtle transition-colors focus:border-focus focus:outline-none focus:ring-3 focus:ring-focus/25'
        .' disabled:cursor-not-allowed disabled:bg-sunken aria-invalid:border-danger',
]) }}>{{ $slot }}</textarea>
