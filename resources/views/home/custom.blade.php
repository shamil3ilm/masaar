{{--
    Need something else? Another country, a feature or a customization: an
    invitation to ask, with no promise of when or whether - only that we will
    answer. Each item, and the main action, opens the contact form below with
    its topic chosen (?topic= without script, in place with resources/js/ui.js).

    The words are Admin › Website's. The three asks are a fixed list: each is
    tied here to its contact topic and icon, so only what they say changes.
--}}
@php
    $topics = [
        ['country', 'M12 21a9 9 0 100-18 9 9 0 000 18zm0 0c2.5-2.4 3.75-5.4 3.75-9S14.5 5.4 12 3m0 18c-2.5-2.4-3.75-5.4-3.75-9S9.5 5.4 12 3M3.5 9h17m-17 6h17'],
        ['feature', 'M12 4v16m8-8H4'],
        ['customization', 'M10.3 4.3a1.7 1.7 0 013.4 0 1.7 1.7 0 002.6 1 1.7 1.7 0 012.4 2.4 1.7 1.7 0 001 2.6 1.7 1.7 0 010 3.4 1.7 1.7 0 00-1 2.6 1.7 1.7 0 01-2.4 2.4 1.7 1.7 0 00-2.6 1 1.7 1.7 0 01-3.4 0 1.7 1.7 0 00-2.6-1 1.7 1.7 0 01-2.4-2.4 1.7 1.7 0 00-1-2.6 1.7 1.7 0 010-3.4 1.7 1.7 0 001-2.6 1.7 1.7 0 012.4-2.4 1.7 1.7 0 002.6-1zM15 12a3 3 0 11-6 0 3 3 0 016 0z'],
    ];
@endphp

<section id="custom" class="relative scroll-mt-20 py-14 sm:py-16" aria-labelledby="custom-title">
    <span class="route-stop" aria-hidden="true"></span>
    <x-ui.section-heading id="custom-title" :eyebrow="$section->text('eyebrow')"
        :title="$section->text('title')" :pair="$section->pair('title')" />
    <p class="mt-4 max-w-2xl text-muted">{{ $section->html('intro') }}</p>

    <ul class="mt-8 grid gap-4 md:grid-cols-3">
        @foreach ($section->items('asks') as $index => $ask)
            @php([$topic, $icon] = $topics[$index] ?? $topics[0])
            <li class="flex flex-col rounded-card border border-line bg-surface p-6">
                <svg class="size-7 text-teal-500" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $icon }}" /></svg>
                <h3 class="mt-4 text-base font-semibold">{{ $ask['title'] }}</h3>
                <p class="mt-1.5 flex-1 text-sm leading-relaxed text-muted">{{ $section->rich($ask['detail']) }}</p>
                <a href="{{ $publicSite->contactLink($topic) }}" data-contact-topic="{{ $topic }}"
                   class="mt-4 inline-flex min-h-11 items-center gap-1.5 self-start text-sm font-semibold text-accent hover:underline underline-offset-4">
                    {{ $section->text('ask_action') }}
                    <svg class="size-4 rtl:rotate-180" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 6l6 6-6 6" /></svg>
                </a>
            </li>
        @endforeach
    </ul>

    {{-- On request, we set it up with the customer's team. Said plainly, in
         our own voice: what we do, not what it costs or how long it takes. --}}
    <div class="mt-4 grid gap-6 rounded-card border border-line bg-surface p-6 sm:p-8 lg:grid-cols-[1fr_auto] lg:items-center">
        <div>
            <h3 class="text-heading font-semibold">{{ $section->text('setup_title') }}</h3>
            <p class="mt-2 max-w-2xl text-sm leading-relaxed text-muted">{{ $section->html('setup_intro') }}</p>
            <ul class="mt-3 grid gap-x-6 gap-y-1 text-sm sm:grid-cols-2">
                @foreach ($section->items('setup_items') as $task)
                    <li class="flex items-start gap-2">
                        <svg class="mt-0.5 size-4 shrink-0 text-accent" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M5 12.5l4.5 4.5L19 7.5" /></svg>
                        {{ $task['text'] }}
                    </li>
                @endforeach
            </ul>
        </div>
        <x-ui.button variant="secondary" :href="$publicSite->contactLink('engineer')" data-contact-topic="engineer">{{ $section->text('setup_action') }}</x-ui.button>
    </div>

    <x-ui.button size="lg" class="mt-8" :href="$publicSite->contactLink('general')" data-contact-topic="general">{{ $section->text('action') }}</x-ui.button>
</section>
