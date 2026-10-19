@props(['lines' => 3, 'label' => null])

{{--
    The shape of what is loading: grey bars where text will be, so the page
    does not jump when it arrives. Hidden from a screen reader, which hears
    the label instead ("Loading" unless given). The shimmer stops for anyone
    who asks for less motion.
--}}
<div {{ $attributes->merge(['class' => 'space-y-2']) }} data-skeleton>
    <span class="sr-only" role="status">{{ $label ?? __('Loading') }}</span>
    @for ($line = 0; $line < $lines; $line++)
        <span aria-hidden="true" @class([
            'block h-3 rounded-full bg-sunken motion-safe:animate-pulse',
            'w-full' => $line < $lines - 1,
            'w-2/3' => $line === $lines - 1,
        ])></span>
    @endfor
</div>
