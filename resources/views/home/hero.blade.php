{{--
    The first screen: what this is, the two ways in, and an invoice on its
    way through.

    The invoice is a picture of one, not a real one, and says so to a screen
    reader. Its numbers are set in tabular figures and read left to right in
    either language, as a printed invoice's do.

    Its QR code is a real one a phone can read, encoded as the leading country
    that prints one encodes it, from the card's own figures (PublicSite), and
    labelled a sample beside it. Where no country here prints a QR code, the
    card has none.
--}}
@php
    $sample = $publicSite->sampleInvoice();
    $sampleQr = $publicSite->sampleQr();
    $amount = fn (string $value): string => number_format((float) $value, 2);
@endphp
<section class="mx-auto max-w-page overflow-x-clip px-4 pb-16 pt-12 sm:px-6 sm:pb-24 sm:pt-20" aria-labelledby="hero-title">
    <div class="grid items-center gap-14 lg:grid-cols-[1.15fr_1fr]">
        <div class="min-w-0">
            @if ($countries !== [])
                <p class="inline-flex flex-wrap items-center gap-2 rounded-full bg-accent-soft px-3 py-1.5 text-sm font-medium text-accent">
                    <span class="size-1.5 rounded-full bg-teal-500" aria-hidden="true"></span>
                    {{ __('E-invoicing for') }}
                    @foreach ($countries as $country)
                        <span>{{ __($country->name()) }}</span>@if (! $loop->last)<span aria-hidden="true">&middot;</span>@endif
                    @endforeach
                </p>
            @endif

            <h1 id="hero-title" class="mt-6 text-display font-semibold">
                {{ __('Every invoice,') }}<br>{{ __('on the right path.') }}
            </h1>

            <p class="mt-6 max-w-xl text-lead text-muted">
                {{ __('Issue invoices in the portal or send them from your ERP. Masaar checks, sends and keeps each one the way your tax authority expects - in Arabic and English.') }}
            </p>

            <div class="mt-9 flex flex-wrap gap-3">
                @auth
                    <x-ui.button size="lg" :href="route('portal.dashboard')">{{ __('Open the portal') }}</x-ui.button>
                @else
                    @if (config('signup.enabled'))
                        <x-ui.button size="lg" :href="route('register')">{{ __('Start your free month') }}</x-ui.button>
                    @endif
                    <x-ui.button size="lg" variant="secondary" href="#steps">{{ __('See how it works') }}</x-ui.button>
                @endauth
            </div>

            <p class="mt-4 text-sm text-subtle">{{ __('No card needed to sign up, and your first month is free.') }}</p>
        </div>

        <div class="relative mx-auto w-full max-w-md">
            {{-- The route the invoice travels, behind it. --}}
            <svg class="absolute -inset-x-6 -top-10 h-[115%] w-[calc(100%+3rem)] text-teal-500" viewBox="0 0 420 360" fill="none" aria-hidden="true" preserveAspectRatio="none">
                <path d="M10 330 C80 170, 170 170, 210 280 S350 350, 410 40" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-dasharray="2 10" />
            </svg>

            <figure class="relative -rotate-2 rounded-card bg-paper p-6 text-ink-900 shadow-invoice ring-1 ring-ink-900/5 sm:p-7" role="img" aria-label="{{ __('A sample tax invoice, cleared by the authority') }}">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wider text-ink-700">Tax invoice</p>
                        <p class="mt-0.5 text-sm text-ink-700" lang="ar" dir="rtl">فاتورة ضريبية</p>
                    </div>
                    <span class="inline-flex items-center gap-1.5 rounded-full bg-teal-100 px-2.5 py-1 text-xs font-semibold uppercase tracking-wider text-teal-800">
                        <svg class="size-3.5" fill="none" stroke="currentColor" stroke-width="3" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M5 12.5l4.5 4.5L19 7.5" /></svg>
                        {{ __('Cleared') }}
                    </span>
                </div>

                <div class="mt-5 text-sm" dir="ltr">
                    <p class="font-semibold">{{ $sample->sellerName }}</p>
                    @if ($sampleQr)
                        <p class="font-mono text-xs tabular-nums text-ink-700">{{ $sampleQr['taxNumber'] }}</p>
                    @endif
                    <p class="mt-2 flex flex-wrap justify-between gap-x-4 font-mono font-medium tabular-nums">
                        <span>{{ $sample->number }}</span>
                        <span class="text-ink-700">{{ $sample->issuedAt->format('Y-m-d') }}</span>
                    </p>
                </div>

                <dl class="mt-5 grid gap-2 border-t border-dashed border-ink-900/20 pt-4 text-sm" dir="ltr">
                    @foreach ($sample->lines as $description => $value)
                        <div class="flex justify-between gap-4"><dt>{{ $description }}</dt><dd class="font-mono tabular-nums">{{ $amount($value) }}</dd></div>
                    @endforeach
                    <div class="flex justify-between gap-4 text-ink-700"><dt>VAT</dt><dd class="font-mono tabular-nums">{{ $amount($sample->tax) }}</dd></div>
                    <div class="flex justify-between gap-4 border-t border-ink-900/10 pt-2.5 font-semibold"><dt>Total</dt><dd class="font-mono tabular-nums">{{ $amount($sample->total) }}</dd></div>
                </dl>

                <div class="mt-5 flex items-center gap-4">
                    @if ($sampleQr)
                        {{-- Drawn with its quiet zone on white, so it reads on
                             the paper in either theme, and turned back
                             square to the screen so its modules land on whole
                             pixels rather than the card's tilt. --}}
                        <div class="shrink-0 rotate-2 text-center">
                            <div class="leading-none" data-sample-qr>{!! $sampleQr['svg'] !!}</div>
                            <p class="mt-1 text-xs font-semibold uppercase tracking-wider text-ink-700">{{ __('Sample') }}</p>
                        </div>
                    @endif
                    <p class="text-xs leading-relaxed text-ink-700">{{ __('Checked, filed with the authority and kept - every one, in order.') }}</p>
                </div>
            </figure>
        </div>
    </div>
</section>
