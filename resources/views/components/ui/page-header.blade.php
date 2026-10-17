@props(['title', 'description' => null, 'eyebrow' => null])

{{--
    The top of a screen: what it is, a line on what it is for, and the
    actions that belong to the whole screen.

    Fifty screens opened with an h2 in text-2xl font-bold and a grey
    paragraph, each written out, in three spacings. This is the one heading
    level a screen has - h1 - so a screen reader's list of headings starts
    with the screen's name rather than with the layout's.

    eyebrow      a small line above the title: the section it sits in.
    @slot actions        buttons, at the end edge on a wide screen and under
                         the title on a phone.
    @slot breadcrumb     an <x-ui.breadcrumb>, above everything.
--}}
<div {{ $attributes->merge(['class' => 'mb-6']) }}>
    @isset($breadcrumb)
        <div class="mb-3">{{ $breadcrumb }}</div>
    @endisset
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div class="min-w-0">
            @if ($eyebrow)
                <p class="text-eyebrow font-semibold uppercase text-accent">{{ $eyebrow }}</p>
            @endif
            <h1 class="text-2xl font-semibold tracking-tight text-fg @if ($eyebrow) mt-1 @endif">{{ $title }}</h1>
            @if ($description)
                <p class="mt-1 max-w-3xl text-sm text-muted">{{ $description }}</p>
            @endif
        </div>
        @isset($actions)
            <div class="flex flex-wrap items-center gap-2">{{ $actions }}</div>
        @endisset
    </div>
</div>
