@props(['href' => null, 'tone' => null, 'type' => 'submit'])

{{--
    One entry in an <x-ui.menu>: a link with href, otherwise a submit button
    - put it inside the form it submits, such as Sign out.

    tone danger for an entry that cannot be undone.
--}}
@php
    $classes = 'flex min-h-11 w-full items-center gap-2 rounded-control px-3 text-start text-sm transition-colors hover:bg-sunken focus:bg-sunken focus:outline-none '
        .($tone === 'danger' ? 'text-danger' : 'text-fg');
@endphp

@if ($href)
    <a href="{{ $href }}" role="menuitem" tabindex="-1" {{ $attributes->merge(['class' => $classes]) }}>{{ $slot }}</a>
@else
    <button type="{{ $type }}" role="menuitem" tabindex="-1" {{ $attributes->merge(['class' => $classes]) }}>{{ $slot }}</button>
@endif
