@props(['rows' => 3])

{{--
    A longer piece of text - a note, a reason.

    Here for the same reason as x-ui.input and x-ui.select: one border, one
    radius, one focus ring. min-h-11 is not applied, because a textarea's
    height is its rows and the smallest of those is already taller than a
    touch target.
--}}
<textarea rows="{{ $rows }}" {{ $attributes->merge([
    'class' => 'mt-1 block w-full rounded border-gray-300 dark:border-gray-700 dark:bg-gray-900 text-sm font-normal text-gray-900 dark:text-gray-100 shadow-sm'
        .' focus:border-teal-600 focus:ring-1 focus:ring-teal-600',
]) }}>{{ $slot }}</textarea>
