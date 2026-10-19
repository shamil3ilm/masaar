{{--
    A colour: our swatch and a hex code you can type or paste.

    The browser's colour input is kept as the value - what the form sends,
    #rrggbb - and opens the system's picker when the swatch is pressed, but
    it is drawn as our swatch (ui-swatch in app.css) rather than the
    platform's grey well. resources/js/color-field.js puts a hex field beside
    it that follows the swatch and sets it, so a brand colour copied from a
    style guide goes in as typed.

    Attributes pass to the colour input: name, value, aria-label.
--}}
<span data-color-field class="mt-1 inline-flex items-center gap-2">
    <input type="color" {{ $attributes->merge(['class' => 'ui-swatch size-11 shrink-0 cursor-pointer rounded-control border border-line-strong bg-surface p-1 shadow-card focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus']) }}>
</span>
