@props(['title', 'pair' => null, 'eyebrow' => null, 'level' => 2, 'inverse' => false])

{{--
    A section's heading, in both languages.

    The product is bilingual by law as much as by choice - an invoice's Arabic
    is the authoritative text - so a public section says its heading in the
    page's language and, beside it, in the other one. The second is its own
    language and direction, so a screen reader announces it as that language
    and the browser shapes it correctly, and it is quieter than the first: a
    reader of either language finds the heading meant for them.

    title    the heading, translated into the page's language as usual
    pair     the same heading in the other language: the same key, with the
             other locale passed to the translator. Left out, or the same as the title, and only one is shown.
    eyebrow  a small accent line above, naming the section.
    inverse  for a section on navy.
--}}
@php
    $here = app()->getLocale();
    $there = $here === 'ar' ? 'en' : 'ar';
    $tag = 'h'.max(1, min(6, (int) $level));
@endphp

<div {{ $attributes->merge(['class' => 'flex flex-col gap-1']) }}>
    @if ($eyebrow)
        <p @class(['text-eyebrow font-semibold uppercase', 'text-accent' => ! $inverse, 'text-teal-300' => $inverse])>{{ $eyebrow }}</p>
    @endif
    <div class="flex flex-wrap items-baseline justify-between gap-x-8 gap-y-1">
        <{{ $tag }} @class(['text-title font-semibold', 'text-fg' => ! $inverse, 'text-white' => $inverse])>{{ $title }}</{{ $tag }}>
        @if ($pair !== null && $pair !== $title)
            <p lang="{{ $there }}" dir="{{ $there === 'ar' ? 'rtl' : 'ltr' }}" @class(['text-heading font-semibold', 'text-subtle' => ! $inverse, 'text-white/60' => $inverse])>{{ $pair }}</p>
        @endif
    </div>
</div>
