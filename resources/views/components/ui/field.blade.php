@props(['label', 'hint' => null, 'error' => null, 'optional' => false])

{{--
    A control with a name a screen reader can read.

    The filter controls on admin/logs and admin/queue had a placeholder and
    nothing else. A placeholder is not a name: it vanishes the moment someone
    types, so it cannot be re-read.

    The control is nested inside the label rather than paired by id: an id
    has to be unique on the page, and a component used twice in one form would
    duplicate it. Nesting has nothing to collide.

    hint      what to enter and why, under the label, read out with it.
    error     the field's name in the error bag. Its first message is shown
              under the control; without one nothing is drawn.
    optional  says so beside the label, so an optional field is not mistaken
              for a required one.
--}}
@php
    $message = $error !== null ? $errors->first($error) : null;
@endphp

<label {{ $attributes->merge(['class' => 'block text-sm font-medium text-fg']) }}>
    <span class="flex flex-wrap items-baseline gap-x-2">
        <span data-field-label>{{ $label }}</span>
        @if ($optional)
            <span class="text-xs font-normal text-subtle">{{ __('Optional') }}</span>
        @endif
    </span>
    @if ($hint)
        <span class="mt-0.5 block text-xs font-normal text-subtle">{{ $hint }}</span>
    @endif
    {{ $slot }}
    @if ($message)
        <span class="mt-1 block text-xs font-medium text-danger" data-field-error>{{ $message }}</span>
    @endif
</label>
