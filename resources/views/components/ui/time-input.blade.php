{{--
    A time of day to enter: the browser's own time input, with a list of
    times in front of it (resources/js/date-field.js).

    The native input is the value - hours:minutes, 24-hour - and all that
    shows without script. With it, a text box takes a time typed as people
    type one (13:30, 1330, 1:30 pm, ١:٣٠ م) and offers the times on the
    native input's step, half an hour unless it says otherwise.

    Attributes pass to the native input: name, value, min, max, step,
    required. Sits in an x-ui.field like any control.
--}}
<span data-time-field class="block">
    <x-ui.input type="time" {{ $attributes }} />
</span>
