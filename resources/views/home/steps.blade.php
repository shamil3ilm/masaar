{{-- How it works: from signing up to the first invoice. The last step is the one the page is for. --}}
<section id="steps" class="relative scroll-mt-20 py-14 sm:py-16" aria-labelledby="steps-title">
    <span class="route-stop" aria-hidden="true"></span>
    <x-ui.section-heading id="steps-title" :eyebrow="__('How it works')"
        :title="__('Four steps to your first invoice.')"
        :pair="__('Four steps to your first invoice.', [], $pair)" />

    <ol class="mt-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        @foreach ([
            __('Create your account with your company\'s tax registration and address.'),
            __('We review your company. Once it is approved, your free first month begins.'),
            __('Connect to your country\'s e-invoicing system, with the details the portal asks for.'),
            __('Issue your first invoice.'),
        ] as $step)
            <li @class([
                'rounded-tile p-6',
                'border border-line bg-surface' => ! $loop->last,
                'bg-ink-900 text-white' => $loop->last,
            ])>
                <span @class(['font-mono text-sm font-semibold tabular-nums', 'text-accent' => ! $loop->last, 'text-teal-300' => $loop->last]) dir="ltr">{{ sprintf('%02d', $loop->iteration) }}</span>
                <p class="mt-3 leading-relaxed">{{ $step }}</p>
            </li>
        @endforeach
    </ol>
</section>
