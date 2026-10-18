<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ \App\Http\Middleware\SetLocale::rtl() ? 'rtl' : 'ltr' }}" class="{{ $theme->htmlClass() }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', __('ZATCA Compliance Portal'))</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <x-theme-head />
</head>
<body class="min-h-screen bg-ground text-fg">
    <a href="#content" class="sr-only focus:not-sr-only focus:fixed focus:start-4 focus:top-4 focus:z-50 focus:rounded-button focus:bg-surface focus:px-4 focus:py-3 focus:shadow-raised">{{ __('Skip to content') }}</a>

    <div x-data="drawer" class="flex">
        <div x-show="covering" @click="close" x-cloak
             class="fixed inset-0 z-30 bg-ink-950/50" aria-hidden="true"></div>

        {{-- Which country's tagline and links to show (Presentation). Not every screen passes the organization. --}}
        @php($navOrganization = $organization ?? app(\App\Domains\Organization\Services\TenantResolver::class)->getOrganization())
        @php($navCountry = app(\App\Domains\Compliance\Engine\Services\JurisdictionResolver::class)->forRules($navOrganization)->presentation())

        <aside id="sidebar" :class="panel"
               class="fixed inset-y-0 start-0 z-40 flex flex-col overflow-y-auto border-e border-line bg-surface transition-transform duration-300 md:sticky md:top-0 md:z-auto md:h-screen">
            <div class="border-b border-line px-5 py-5">
                <a href="{{ route('portal.dashboard') }}" class="inline-flex rounded">
                    <x-logo :size="30" name name-class="text-base font-semibold tracking-tight text-fg" />
                </a>
                <p class="mt-2 text-xs font-medium uppercase tracking-wide text-subtle">{{ $navCountry->tagline() }}</p>
                @if (isset($organization))
                    <p class="mt-3 truncate rounded-control bg-sunken px-3 py-2 text-sm font-medium text-fg">{{ $organization->name }}</p>
                @endif
            </div>

            <nav class="flex-1 space-y-1 p-3" aria-label="{{ __('Portal') }}">
                {{-- The active organization lives in the session; links carry no tenant identifier. --}}
                <x-ui.nav-link :href="route('portal.dashboard')" :active="request()->routeIs('portal.dashboard')">
                    <x-slot:icon><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"></path></svg></x-slot:icon>
                    {{ __('Dashboard') }}
                </x-ui.nav-link>
                <x-ui.nav-link :href="route('portal.invoices.index')" :active="request()->routeIs('portal.invoices.*')">
                    <x-slot:icon><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M9 14h6m-6-4h6m4 10H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v12a2 2 0 01-2 2z"></path></svg></x-slot:icon>
                    {{ __('Invoices') }}
                </x-ui.nav-link>
                {{-- The links the country adds for everyone. --}}
                @if ($navLinks = $navCountry->navigation(\App\Domains\Compliance\Engine\Contracts\Presentation::NAV_MAIN))
                    @include($navLinks)
                @endif
                <x-ui.nav-link :href="route('portal.customers.index')" :active="request()->routeIs('portal.customers.*')">
                    <x-slot:icon><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path></svg></x-slot:icon>
                    {{ __('Customers') }}
                </x-ui.nav-link>
                <x-ui.nav-link :href="route('portal.products.index')" :active="request()->routeIs('portal.products.*')">
                    <x-slot:icon><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path></svg></x-slot:icon>
                    {{ __('Products') }}
                </x-ui.nav-link>
                <x-ui.nav-link :href="route('portal.submissions')" :active="request()->routeIs('portal.submissions')">
                    <x-slot:icon><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg></x-slot:icon>
                    {{ __('Submissions') }}
                </x-ui.nav-link>
                <x-ui.nav-link :href="route('portal.certificates')" :active="request()->routeIs('portal.certificates')">
                    <x-slot:icon><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"></path></svg></x-slot:icon>
                    {{ __('Certificates') }}
                </x-ui.nav-link>
                <x-ui.nav-link :href="route('portal.reports')" :active="request()->routeIs('portal.reports*')">
                    <x-slot:icon><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg></x-slot:icon>
                    {{ __('Reports') }}
                </x-ui.nav-link>
                <x-ui.nav-link :href="route('portal.support.index')" :active="request()->routeIs('portal.support.*')">
                    <x-slot:icon><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z"></path></svg></x-slot:icon>
                    {{ __('Support') }}
                </x-ui.nav-link>
                {{-- Everyone's: a person chooses their own paper there. --}}
                <x-ui.nav-link :href="route('portal.printing.edit')" :active="request()->routeIs('portal.printing.*')">
                    <x-slot:icon><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"></path></svg></x-slot:icon>
                    {{ __('Printing') }}
                </x-ui.nav-link>
                <x-ui.nav-link :href="route('portal.preferences.edit')" :active="request()->routeIs('portal.preferences.*')">
                    <x-slot:icon><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M12 6V4m0 2a2 2 0 100 4m0-4a2 2 0 110 4m-6 8a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4m6 6v10m6-2a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4"></path></svg></x-slot:icon>
                    {{ __('Preferences') }}
                </x-ui.nav-link>
                <x-ui.nav-link :href="route('portal.qr-codes.index')" :active="request()->routeIs('portal.qr-codes.*')">
                    <x-slot:icon><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M4 4h6v6H4V4zm10 0h6v6h-6V4zM4 14h6v6H4v-6zm10 0h2m4 0v2m-4 4h4m-6-2v2"></path></svg></x-slot:icon>
                    {{ __('QR codes') }}
                </x-ui.nav-link>

                {{-- Its administrators only; the page itself refuses anyone else. --}}
                @if (auth()->user()?->roleIn((string) request()->attributes->get(\App\Domains\Organization\Http\Middleware\PortalTenant::ORG_ID)) === 'admin')
                    <p class="px-3 pb-1 pt-5 text-xs font-semibold uppercase tracking-wider text-subtle">{{ __('Organization settings') }}</p>
                    <x-ui.nav-link :href="route('portal.company.edit')" :active="request()->routeIs('portal.company.*')">
                        <x-slot:icon><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"></path></svg></x-slot:icon>
                        {{ __('Company') }}
                    </x-ui.nav-link>
                    <x-ui.nav-link :href="route('portal.settings.edit')" :active="request()->routeIs('portal.settings.*')">
                        <x-slot:icon><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M10.3 4.3c.4-1.8 3-1.8 3.4 0a1.7 1.7 0 002.6 1.1c1.5-.9 3.3.8 2.4 2.4a1.7 1.7 0 001 2.5c1.8.5 1.8 3 0 3.5a1.7 1.7 0 00-1 2.5c.9 1.6-.9 3.3-2.4 2.4a1.7 1.7 0 00-2.6 1.1c-.4 1.8-3 1.8-3.4 0a1.7 1.7 0 00-2.6-1.1c-1.5.9-3.3-.8-2.4-2.4a1.7 1.7 0 00-1-2.5c-1.8-.5-1.8-3 0-3.5a1.7 1.7 0 001-2.5c-.9-1.6.9-3.3 2.4-2.4a1.7 1.7 0 002.6-1.1zM15 12a3 3 0 11-6 0 3 3 0 016 0z"></path></svg></x-slot:icon>
                        {{ __('Settings') }}
                    </x-ui.nav-link>
                    <x-ui.nav-link :href="route('portal.team.index')" :active="request()->routeIs('portal.team.*')">
                        <x-slot:icon><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"></path></svg></x-slot:icon>
                        {{ __('Team') }}
                    </x-ui.nav-link>
                    {{-- And for its administrators. --}}
                    @if ($navLinks = $navCountry->navigation(\App\Domains\Compliance\Engine\Contracts\Presentation::NAV_ADMIN))
                        @include($navLinks)
                    @endif
                    <x-ui.nav-link :href="route('portal.numbering.edit')" :active="request()->routeIs('portal.numbering.*')">
                        <x-slot:icon><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M7 20l4-16m2 16l4-16M6 9h14M4 15h14"></path></svg></x-slot:icon>
                        {{ __('Numbering') }}
                    </x-ui.nav-link>
                    <x-ui.nav-link :href="route('portal.branding.edit')" :active="request()->routeIs('portal.branding.*')">
                        <x-slot:icon><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M7 21a4 4 0 01-4-4V5a2 2 0 012-2h4a2 2 0 012 2v12a4 4 0 01-4 4zm0 0h12a2 2 0 002-2v-4a2 2 0 00-2-2h-2.343M11 7.343l1.657-1.657a2 2 0 012.828 0l2.829 2.829a2 2 0 010 2.828l-8.486 8.485M7 17h.01"></path></svg></x-slot:icon>
                        {{ __('Branding') }}
                    </x-ui.nav-link>
                    <x-ui.nav-link :href="route('portal.billing')" :active="request()->routeIs('portal.billing*')">
                        <x-slot:icon><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"></path></svg></x-slot:icon>
                        {{ __('Plan and billing') }}
                    </x-ui.nav-link>
                @endif
            </nav>

            <div class="space-y-1 border-t border-line p-4">
                @auth
                    <a href="{{ route('account.show') }}" class="flex min-h-11 items-center gap-2 truncate rounded text-sm text-muted hover:text-fg hover:underline underline-offset-4">
                        <svg class="size-5 shrink-0" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M5.1 18.4A9 9 0 0112 15a9 9 0 016.9 3.4M15 9a3 3 0 11-6 0 3 3 0 016 0zm6 3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        <span class="truncate">{{ auth()->user()->email }}</span>
                    </a>
                    <div class="flex flex-wrap items-center gap-x-4">
                        <a href="{{ route('portal.switch') }}" class="inline-flex min-h-11 items-center text-sm font-medium text-accent hover:text-accent-strong hover:underline underline-offset-4">{{ __('Switch organization') }}</a>
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <x-ui.link-button tone="muted">{{ __('Sign out') }}</x-ui.link-button>
                        </form>
                    </div>
                    <x-locale-switch class="block" />
                @endauth
                <p class="pt-1 text-xs text-subtle">{{ __('Powered by Masaar') }}</p>
            </div>
        </aside>

        {{-- min-w-0 is what makes a table's scroll area work at all: a flex
             child will not shrink below its content without it. --}}
        <main class="min-w-0 flex-1">
            <x-zatca-environment-banner />
            {{-- The portal had no way to reach its navigation on a phone. This
                 bar carries the toggle, and disappears at md where the sidebar
                 is a column again. --}}
            <div class="sticky top-0 z-20 flex items-center gap-3 border-b border-line bg-ground/90 px-4 py-2 backdrop-blur md:hidden">
                <button type="button" @click="toggle" :aria-expanded="expanded"
                        aria-controls="sidebar" aria-label="{{ __('Navigation') }}"
                        class="-ms-2 inline-flex min-h-11 min-w-11 items-center justify-center rounded-button text-muted transition-colors hover:bg-sunken hover:text-fg focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus">
                    <svg class="size-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path></svg>
                </button>
                <x-logo :size="26" name name-class="text-sm font-semibold tracking-tight text-fg" />
            </div>

            <div id="content" class="mx-auto max-w-page space-y-6 p-4 md:p-8">
                {{-- In the layout, not in each page. Included by hand it reached
                     two of eleven portal views, and the pages that redirect with a
                     message were mostly not among them. --}}
                <x-ui.flash />
                @if (isset($organization) && $organization->isPendingReview())
                    <x-ui.alert tone="warning" :title="__('Your company is being reviewed.')">
                        {{ __('You can complete its details and prepare invoices now. Connecting to e-invoicing and issuing open once it is approved.') }}
                    </x-ui.alert>
                @endif
                {{-- The organization's country switched off on the admin
                     console's Countries page (or off by default): the same
                     words for every country. --}}
                @if ($navOrganization && ! app(\App\Domains\Compliance\Engine\Services\JurisdictionResolver::class)->available($navOrganization))
                    <x-ui.alert tone="warning" :title="__('E-invoicing in your country is switched off on this platform.')">
                        {{ __('No new invoice can be issued until it is switched back on. Invoices already issued are still filed or sent, and you can view and download them.') }}
                    </x-ui.alert>
                @endif
                <div>
                    @yield('content')
                </div>
            </div>
        </main>
    </div>

    <x-ui.confirm-dialog />
</body>
</html>
