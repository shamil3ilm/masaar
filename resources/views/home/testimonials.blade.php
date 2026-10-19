{{--
    What customers said, as Admin › Website keeps it: published ones only,
    in their order. The section is off until an admin turns it on, and is
    left out while nothing is published (WebsiteSections::page()).

    Every word is plain text, escaped; a logo is served by the website's own
    route, never from where it was uploaded.
--}}
@php
    $locale = app()->getLocale();
@endphp
<section id="testimonials" class="relative scroll-mt-20 py-14 sm:py-16" aria-labelledby="testimonials-title">
    <span class="route-stop" aria-hidden="true"></span>
    <x-ui.section-heading id="testimonials-title" :eyebrow="$section->text('eyebrow')"
        :title="$section->text('title')" :pair="$section->pair('title')" />

    <ul class="mt-8 grid gap-4 md:grid-cols-2 lg:grid-cols-3">
        @foreach ($testimonials as $testimonial)
            <li class="flex flex-col rounded-card border border-line bg-surface p-6" data-testimonial>
                <blockquote class="flex-1 text-base leading-relaxed text-fg">
                    <p>{{ $section->rich($testimonial->say('quote', $locale)) }}</p>
                </blockquote>
                <div class="mt-5 flex items-center gap-3 border-t border-line pt-4">
                    @if ($testimonial->logo_path)
                        <img src="{{ route('website.testimonials.logo', ['testimonialId' => $testimonial->id, 'v' => $testimonial->updated_at?->timestamp]) }}"
                             alt="{{ $testimonial->say('company', $locale) }}" class="size-10 shrink-0 rounded-tile bg-paper object-contain" loading="lazy">
                    @endif
                    <div class="min-w-0 text-sm">
                        <p class="font-semibold">{{ $testimonial->say('name', $locale) }}</p>
                        @php($about = array_filter([$testimonial->say('role', $locale), $testimonial->say('company', $locale)]))
                        @if ($about !== [])
                            <p class="text-muted">{{ implode(__(', '), $about) }}</p>
                        @endif
                    </div>
                </div>
            </li>
        @endforeach
    </ul>
</section>
