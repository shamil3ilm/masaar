{{--
    Need something else? Another country, a feature or a customization: an
    invitation to ask, with no promise of when or whether - only that we will
    answer. Each item, and the main action, opens the contact form below with
    its topic chosen (?topic= without script, in place with resources/js/ui.js).
--}}
@php
    $asks = [
        ['country', __('Another country'), __('We can add another country\'s e-invoicing rules to the platform. Tell us where you need to file.'), 'M12 21a9 9 0 100-18 9 9 0 000 18zm0 0c2.5-2.4 3.75-5.4 3.75-9S14.5 5.4 12 3m0 18c-2.5-2.4-3.75-5.4-3.75-9S9.5 5.4 12 3M3.5 9h17m-17 6h17'],
        ['feature', __('A feature'), __('An integration, document scanning (OCR), a report or anything else: tell us what would help, and we will say whether and how we can build it.'), 'M12 4v16m8-8H4'],
        ['customization', __('A customization'), __('Workflows, layouts and terms made for the way your business works.'), 'M10.3 4.3a1.7 1.7 0 013.4 0 1.7 1.7 0 002.6 1 1.7 1.7 0 012.4 2.4 1.7 1.7 0 001 2.6 1.7 1.7 0 010 3.4 1.7 1.7 0 00-1 2.6 1.7 1.7 0 01-2.4 2.4 1.7 1.7 0 00-2.6 1 1.7 1.7 0 01-3.4 0 1.7 1.7 0 00-2.6-1 1.7 1.7 0 01-2.4-2.4 1.7 1.7 0 00-1-2.6 1.7 1.7 0 010-3.4 1.7 1.7 0 001-2.6 1.7 1.7 0 012.4-2.4 1.7 1.7 0 002.6-1zM15 12a3 3 0 11-6 0 3 3 0 016 0z'],
    ];
@endphp

<section id="custom" class="relative scroll-mt-20 py-14 sm:py-16" aria-labelledby="custom-title">
    <span class="route-stop" aria-hidden="true"></span>
    <x-ui.section-heading id="custom-title" :eyebrow="__('Open to what you need')"
        :title="__('Need something else?')"
        :pair="__('Need something else?', [], $pair)" />
    <p class="mt-4 max-w-2xl text-muted">{{ __('Masaar is open to any country, any feature and any customization. Ask, and we will tell you what we can do.') }}</p>

    <ul class="mt-8 grid gap-4 md:grid-cols-3">
        @foreach ($asks as [$topic, $title, $detail, $icon])
            <li class="flex flex-col rounded-card border border-line bg-surface p-6">
                <svg class="size-7 text-teal-500" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $icon }}" /></svg>
                <h3 class="mt-4 text-base font-semibold">{{ $title }}</h3>
                <p class="mt-1.5 flex-1 text-sm leading-relaxed text-muted">{{ $detail }}</p>
                <a href="{{ url('/') }}?topic={{ $topic }}#contact" data-contact-topic="{{ $topic }}"
                   class="mt-4 inline-flex min-h-11 items-center gap-1.5 self-start text-sm font-semibold text-accent hover:underline underline-offset-4">
                    {{ __('Ask us') }}
                    <svg class="size-4 rtl:rotate-180" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 6l6 6-6 6" /></svg>
                </a>
            </li>
        @endforeach
    </ul>

    {{-- On request, we set it up with the customer's team. Said plainly, in
         our own voice: what we do, not what it costs or how long it takes. --}}
    <div class="mt-4 grid gap-6 rounded-card border border-line bg-surface p-6 sm:p-8 lg:grid-cols-[1fr_auto] lg:items-center">
        <div>
            <h3 class="text-heading font-semibold">{{ __('We set it up with you') }}</h3>
            <p class="mt-2 max-w-2xl text-sm leading-relaxed text-muted">{{ __('On request, we work with your team, on site or remotely, to set Masaar up end to end:') }}</p>
            <ul class="mt-3 grid gap-x-6 gap-y-1 text-sm sm:grid-cols-2">
                @foreach ([
                    __('we connect your ERP or store;'),
                    __('we configure your branches, tills and printers;'),
                    __('we migrate your data;'),
                    __('we train your staff;'),
                    __('we stay with you through go-live.'),
                ] as $task)
                    <li class="flex items-start gap-2">
                        <svg class="mt-0.5 size-4 shrink-0 text-accent" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M5 12.5l4.5 4.5L19 7.5" /></svg>
                        {{ $task }}
                    </li>
                @endforeach
            </ul>
        </div>
        <x-ui.button variant="secondary" :href="url('/').'?topic=engineer#contact'" data-contact-topic="engineer">{{ __('Talk to us') }}</x-ui.button>
    </div>

    <x-ui.button size="lg" class="mt-8" :href="url('/').'?topic=general#contact'" data-contact-topic="general">{{ __('Tell us what you need') }}</x-ui.button>
</section>
