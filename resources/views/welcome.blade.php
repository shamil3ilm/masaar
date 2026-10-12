<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Masaar &mdash; ZATCA e-invoicing</title>
    <meta name="description" content="Masaar signs, chains and reports e-invoices to ZATCA.">
    <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="flex min-h-full flex-col bg-ink-900 text-white">
    <header class="px-6 py-6 sm:px-10">
        <x-logo :size="36" name name-class="text-xl font-semibold tracking-tight" />
    </header>

    <main class="flex flex-1 items-center px-6 pb-16 sm:px-10">
        <div class="mx-auto w-full max-w-3xl">
            <p class="text-sm font-medium tracking-widest text-teal-400 uppercase">Saudi Arabia &middot; Fatoora</p>

            <h1 class="mt-4 text-4xl font-semibold tracking-tight sm:text-5xl">
                Every invoice signed, chained and reported.
            </h1>

            <p class="mt-6 max-w-2xl text-lg text-white/70">
                Masaar takes an invoice, signs it to ZATCA's Phase&nbsp;2 specification, keeps it in an
                unbroken hash chain, and files it with the authority inside the window the rules allow.
                When the authority cannot be reached, nothing is lost &mdash; the invoice is queued and
                filed when it can be.
            </p>

            <div class="mt-10 flex flex-wrap items-center gap-4">
                @auth
                    <a href="{{ route('portal.dashboard') }}"
                       class="rounded-lg bg-teal-500 px-5 py-3 text-sm font-semibold text-ink-950 transition-colors hover:bg-teal-400">
                        Open the portal
                    </a>
                    @if (auth()->user()->isPlatformAdmin())
                        <a href="{{ route('admin.dashboard') }}"
                           class="rounded-lg border border-white/25 px-5 py-3 text-sm font-semibold transition-colors hover:border-white/50">
                            Admin console
                        </a>
                    @endif
                @else
                    <a href="{{ route('login') }}"
                       class="rounded-lg bg-teal-500 px-5 py-3 text-sm font-semibold text-ink-950 transition-colors hover:bg-teal-400">
                        Sign in
                    </a>
                @endauth
            </div>

            <dl class="mt-16 grid gap-8 border-t border-white/10 pt-10 sm:grid-cols-3">
                @foreach ([
                    ['Signed', 'XAdES B-B over the canonical document, with the certificate the authority issued.'],
                    ['Chained', 'Each invoice carries the hash of the one before it, and the chain is verified on a schedule.'],
                    ['Reported', 'Cleared or reported within the deadline, with the overdue ones raised before it passes.'],
                ] as [$term, $detail])
                    <div>
                        <dt class="flex items-center gap-2 text-sm font-semibold">
                            <span class="size-1.5 rounded-full bg-sand-200"></span>
                            {{ $term }}
                        </dt>
                        <dd class="mt-2 text-sm text-white/60">{{ $detail }}</dd>
                    </div>
                @endforeach
            </dl>
        </div>
    </main>

    <footer class="border-t border-white/10 px-6 py-6 text-xs text-white/40 sm:px-10">
        Masaar &mdash; e-invoicing compliance.
    </footer>
</body>
</html>
