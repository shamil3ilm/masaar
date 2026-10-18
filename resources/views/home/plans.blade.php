{{--
    Plans: the first month free, then a plan agreed with each customer. No
    prices are published - a plan is shaped around the business, so a price
    list would be wrong for nearly everyone who read it.
--}}
<section id="plans" class="relative scroll-mt-20 py-14 sm:py-16" aria-labelledby="plans-title">
    <span class="route-stop" aria-hidden="true"></span>
    <div class="grid items-center gap-10 rounded-panel bg-ink-900 px-6 py-12 text-white sm:px-12 sm:py-14 lg:grid-cols-[1.4fr_1fr]">
        <div>
            <x-ui.section-heading id="plans-title" inverse :eyebrow="__('Plans')"
                :title="__('Your first month is on us.')"
                :pair="__('Your first month is on us.', [], $pair)" />
            <p class="mt-5 max-w-xl text-lead text-white/75">
                {{ __('After that, we shape a plan around your needs: your volume, your branches and the countries you file in. There is no price list, and nothing is charged without a plan you have agreed to.') }}
            </p>
        </div>
        <div class="flex flex-col gap-3 sm:flex-row lg:flex-col">
            @if (config('signup.enabled'))
                @guest
                    <a href="{{ route('register') }}" class="inline-flex min-h-12 items-center justify-center rounded-button bg-teal-500 px-6 font-semibold text-ink-950 transition-colors hover:bg-teal-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-teal-300">{{ __('Start your free month') }}</a>
                @endguest
            @endif
            <a href="#contact" class="inline-flex min-h-12 items-center justify-center rounded-button border border-white/25 px-6 font-medium text-white transition-colors hover:border-white/60 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-teal-300">{{ __('Talk to us about a plan') }}</a>
        </div>
    </div>
</section>
