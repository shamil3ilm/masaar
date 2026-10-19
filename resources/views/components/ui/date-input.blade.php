{{--
    A date to enter: the browser's own date input, with our calendar in
    front of it (resources/js/date-field.js).

    The native input is the value - year-month-day, Gregorian, what the form
    sends and what min, max and required are checked on - and it is all that
    shows without script. With it, a text box takes a date typed as people
    type one (2026-10-14, 14/10/2026) and a button opens a calendar in the
    page's language, mirrored in Arabic. Where the page names a second
    calendar (data-alt-calendar, Umm al-Qura for a Saudi organization), the
    calendar shows each day in it too, and the field shows the chosen day's.

    Attributes pass to the native input: name, value, min, max, required.
    Sits in an x-ui.field like any control.
--}}
<span data-date-field class="block">
    <x-ui.input type="date" {{ $attributes }} />
</span>
