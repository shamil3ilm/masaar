@extends('layouts.public')

@section('title', __('ZATCA e-invoicing'))

@section('content')
<section class="px-6 pt-10 pb-16 sm:px-10">
    <div class="mx-auto max-w-5xl">
        <p class="text-sm font-medium tracking-widest text-teal-400 uppercase">{{ __('Saudi Arabia - ZATCA Phase 2') }}</p>
        <h1 class="mt-4 max-w-3xl text-4xl font-semibold tracking-tight sm:text-5xl">
            {{ __('Every invoice signed, cleared and reported.') }}
        </h1>
        <p class="mt-6 max-w-2xl text-lg text-white/70">
            {{ __('Issue tax invoices in the portal or send them from your ERP. Masaar signs each one to ZATCA\'s specification, keeps it in an unbroken chain, and clears or reports it with the authority in time - in Arabic and English.') }}
        </p>
        <div class="mt-10 flex flex-wrap items-center gap-4">
            @auth
                <a href="{{ route('portal.dashboard') }}" class="rounded-lg bg-teal-500 px-5 py-3 text-sm font-semibold text-ink-950 transition-colors hover:bg-teal-400">{{ __('Open the portal') }}</a>
            @else
                @if (config('signup.enabled'))
                    <a href="{{ route('register') }}" class="rounded-lg bg-teal-500 px-5 py-3 text-sm font-semibold text-ink-950 transition-colors hover:bg-teal-400">{{ __('Create your account') }}</a>
                @endif
                <a href="{{ route('login') }}" class="rounded-lg border border-white/25 px-5 py-3 text-sm font-semibold transition-colors hover:border-white/50">{{ __('Sign in') }}</a>
            @endauth
        </div>
    </div>
</section>

<section class="border-t border-white/10 px-6 py-16 sm:px-10">
    <div class="mx-auto grid max-w-5xl gap-10 sm:grid-cols-2 lg:grid-cols-3">
        @foreach ([
            [__('Issue in the portal'), __('Bilingual invoices with your saved customers and products, a PDF with the e-invoice inside, and emailing it to the buyer.')],
            [__('Or from your ERP'), __('A documented API, webhooks for every status change, and safe retries, so an invoice is never filed twice.')],
            [__('Signed and chained'), __('Each invoice is signed with your ZATCA certificate and carries the hash of the one before it. The chain is checked on a schedule.')],
            [__('Cleared or reported in time'), __('Standard invoices are cleared before they reach the buyer; simplified ones are reported within the deadline, with late ones raised before it passes.')],
            [__('Nothing lost offline'), __('When ZATCA cannot be reached, invoices are queued and filed as soon as it can.')],
            [__('Ready for your accountant'), __('A VAT summary by return box and CSV exports, with read-only access for whoever does your books.')],
        ] as [$title, $detail])
            <div>
                <h2 class="flex items-center gap-2 text-base font-semibold">
                    <span class="size-1.5 rounded-full bg-sand-200"></span>
                    {{ $title }}
                </h2>
                <p class="mt-2 text-sm text-white/60">{{ $detail }}</p>
            </div>
        @endforeach
    </div>
</section>

<section class="border-t border-white/10 px-6 py-16 sm:px-10">
    <div class="mx-auto max-w-5xl">
        <h2 class="text-2xl font-semibold">{{ __('How it works') }}</h2>
        <ol class="mt-8 grid gap-8 sm:grid-cols-4">
            @foreach ([
                __('Create your account with your company\'s VAT number and national address.'),
                __('We review your company and start your plan.'),
                __('Connect to ZATCA with the one-time code from the Fatoora portal.'),
                __('Issue your first invoice.'),
            ] as $step)
                <li>
                    <span class="text-3xl font-semibold text-teal-400" dir="ltr">{{ $loop->iteration }}</span>
                    <p class="mt-2 text-sm text-white/70">{{ $step }}</p>
                </li>
            @endforeach
        </ol>
    </div>
</section>

<section class="border-t border-white/10 px-6 py-16 sm:px-10">
    <div class="mx-auto max-w-5xl">
        <h2 class="text-2xl font-semibold">{{ __('Plans') }}</h2>
        <p class="mt-2 text-sm text-white/60">{{ __('Monthly, excluding VAT. Paid yearly or for any number of months.') }}</p>
        <div class="mt-8 grid gap-6 sm:grid-cols-2 lg:grid-cols-4">
            @foreach (\App\Domains\Licensing\Enums\LicenseTier::cases() as $tier)
                @php($invoices = $tier->getDefaults()['invoices_per_month'])
                <div class="rounded-lg border border-white/10 p-6">
                    <h3 class="font-semibold">{{ $tier->getDisplayName() }}</h3>
                    <p class="mt-3 text-2xl font-semibold">
                        @if ($tier->getMonthlyPrice() > 0)
                            <span dir="ltr">{{ number_format($tier->getMonthlyPrice()) }} SAR</span>
                        @else
                            {{ __('Contact us') }}
                        @endif
                    </p>
                    <p class="mt-2 text-sm text-white/60">
                        @if ($invoices > 0)
                            {{ __(':count invoices a month', ['count' => number_format($invoices)]) }}
                        @else
                            {{ __('Unlimited invoices') }}
                        @endif
                    </p>
                </div>
            @endforeach
        </div>
        @if (config('company.email'))
            <p class="mt-8 text-sm text-white/70">
                {{ __('Questions before you sign up? Write to') }}
                <a href="mailto:{{ config('company.email') }}" class="text-teal-300 hover:underline">{{ config('company.email') }}</a>
            </p>
        @endif
    </div>
</section>
@endsection
