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
                @if ($navOrganization)
                    <x-branch-switcher :organization-id="(string) $navOrganization->id" />
                @endif
            </div>

            <nav class="flex-1 space-y-1 p-3" aria-label="{{ __('Portal') }}">
                {{-- The active organization lives in the session; links carry no tenant identifier. --}}
                <x-ui.nav-link :href="route('portal.dashboard')" :active="request()->routeIs('portal.dashboard')">
                    <x-slot:icon><x-ui.icon name="home" /></x-slot:icon>
                    {{ __('Dashboard') }}
                </x-ui.nav-link>
                <x-ui.nav-link :href="route('portal.invoices.index')" :active="request()->routeIs('portal.invoices.*', 'portal.notes.*')">
                    <x-slot:icon><x-ui.icon name="invoice" /></x-slot:icon>
                    {{ __('Invoices') }}
                </x-ui.nav-link>
                {{-- The links the country adds for everyone. --}}
                @if ($navLinks = $navCountry->navigation(\App\Domains\Compliance\Engine\Contracts\Presentation::NAV_MAIN))
                    @include($navLinks)
                @endif
                <x-ui.nav-link :href="route('portal.customers.index')" :active="request()->routeIs('portal.customers.*')">
                    <x-slot:icon><x-ui.icon name="user" /></x-slot:icon>
                    {{ __('Customers') }}
                </x-ui.nav-link>
                <x-ui.nav-link :href="route('portal.products.index')" :active="request()->routeIs('portal.products.*')">
                    <x-slot:icon><x-ui.icon name="box" /></x-slot:icon>
                    {{ __('Products') }}
                </x-ui.nav-link>
                <x-ui.nav-link :href="route('portal.submissions')" :active="request()->routeIs('portal.submissions')">
                    <x-slot:icon><x-ui.icon name="document" /></x-slot:icon>
                    {{ __('Submissions') }}
                </x-ui.nav-link>
                <x-ui.nav-link :href="route('portal.certificates')" :active="request()->routeIs('portal.certificates')">
                    <x-slot:icon><x-ui.icon name="shield" /></x-slot:icon>
                    {{ __('Certificates') }}
                </x-ui.nav-link>
                <x-ui.nav-link :href="route('portal.reports')" :active="request()->routeIs('portal.reports*')">
                    <x-slot:icon><x-ui.icon name="chart" /></x-slot:icon>
                    {{ __('Reports') }}
                </x-ui.nav-link>
                <x-ui.nav-link :href="route('portal.support.index')" :active="request()->routeIs('portal.support.*')">
                    <x-slot:icon><x-ui.icon name="chat" /></x-slot:icon>
                    {{ __('Support') }}
                </x-ui.nav-link>
                {{-- Everyone's: a person chooses their own paper there. --}}
                <x-ui.nav-link :href="route('portal.printing.edit')" :active="request()->routeIs('portal.printing.*')">
                    <x-slot:icon><x-ui.icon name="printer" /></x-slot:icon>
                    {{ __('Printing') }}
                </x-ui.nav-link>
                <x-ui.nav-link :href="route('portal.preferences.edit')" :active="request()->routeIs('portal.preferences.*')">
                    <x-slot:icon><x-ui.icon name="sliders" /></x-slot:icon>
                    {{ __('Preferences') }}
                </x-ui.nav-link>
                <x-ui.nav-link :href="route('portal.qr-codes.index')" :active="request()->routeIs('portal.qr-codes.*')">
                    <x-slot:icon><x-ui.icon name="qr" /></x-slot:icon>
                    {{ __('QR codes') }}
                </x-ui.nav-link>

                {{-- Its administrators only; the page itself refuses anyone else. --}}
                @if (\App\Domains\Organization\Enums\MemberRole::administers(auth()->user()?->roleIn((string) request()->attributes->get(\App\Domains\Organization\Http\Middleware\PortalTenant::ORG_ID))))
                    <p class="px-3 pb-1 pt-5 text-xs font-semibold uppercase tracking-wider text-subtle">{{ __('Organization settings') }}</p>
                    <x-ui.nav-link :href="route('portal.company.edit')" :active="request()->routeIs('portal.company.*')">
                        <x-slot:icon><x-ui.icon name="building" /></x-slot:icon>
                        {{ __('Company') }}
                    </x-ui.nav-link>
                    <x-ui.nav-link :href="route('portal.activity')" :active="request()->routeIs('portal.activity*')">
                        <x-slot:icon><x-ui.icon name="clock" /></x-slot:icon>
                        {{ __('Activity') }}
                    </x-ui.nav-link>
                    <x-ui.nav-link :href="route('portal.branches.index')" :active="request()->routeIs('portal.branches.*')">
                        <x-slot:icon><x-ui.icon name="branch" /></x-slot:icon>
                        {{ __('Branches') }}
                    </x-ui.nav-link>
                    <x-ui.nav-link :href="route('portal.settings.edit')" :active="request()->routeIs('portal.settings.*')">
                        <x-slot:icon><x-ui.icon name="settings" /></x-slot:icon>
                        {{ __('Settings') }}
                    </x-ui.nav-link>
                    <x-ui.nav-link :href="route('portal.team.index')" :active="request()->routeIs('portal.team.*')">
                        <x-slot:icon><x-ui.icon name="users" /></x-slot:icon>
                        {{ __('Team') }}
                    </x-ui.nav-link>
                    {{-- And for its administrators. --}}
                    @if ($navLinks = $navCountry->navigation(\App\Domains\Compliance\Engine\Contracts\Presentation::NAV_ADMIN))
                        @include($navLinks)
                    @endif
                    <x-ui.nav-link :href="route('portal.numbering.edit')" :active="request()->routeIs('portal.numbering.*')">
                        <x-slot:icon><x-ui.icon name="hash" /></x-slot:icon>
                        {{ __('Numbering') }}
                    </x-ui.nav-link>
                    <x-ui.nav-link :href="route('portal.branding.edit')" :active="request()->routeIs('portal.branding.*')">
                        <x-slot:icon><x-ui.icon name="palette" /></x-slot:icon>
                        {{ __('Branding') }}
                    </x-ui.nav-link>
                @endif
                {{-- Its administrators', and an accountant's: they read the plan and download its invoices. --}}
                @if (in_array(auth()->user()?->roleIn((string) request()->attributes->get(\App\Domains\Organization\Http\Middleware\PortalTenant::ORG_ID)), ['owner', 'admin', 'accountant'], true))
                    <x-ui.nav-link :href="route('portal.billing')" :active="request()->routeIs('portal.billing*')">
                        <x-slot:icon><x-ui.icon name="card" /></x-slot:icon>
                        {{ __('Plan and billing') }}
                    </x-ui.nav-link>
                @endif
            </nav>

            <div class="space-y-1 border-t border-line p-4">
                @auth
                    <a href="{{ route('account.show') }}" class="flex min-h-11 items-center gap-2 truncate rounded text-sm text-muted hover:text-fg hover:underline underline-offset-4">
                        <x-ui.icon name="user-circle" />
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
                <p class="pt-1 text-xs text-subtle">{{ __('Powered by :app') }}</p>
            </div>
        </aside>

        {{-- min-w-0 is what makes a table's scroll area work at all: a flex
             child will not shrink below its content without it. --}}
        {{-- The country's second calendar, if it keeps one: the date picker
             shows each day in it (resources/js/date-field.js). --}}
        <main class="min-w-0 flex-1" @if ($navCountry->alternateCalendar()) data-alt-calendar="{{ $navCountry->alternateCalendar() }}" @endif>
            <x-zatca-environment-banner />
            @php($portalOrganization = app(\App\Domains\Organization\Services\TenantResolver::class)->getOrganization())
            @if ($portalOrganization?->isSuspended())
                <div class="px-4 pt-4 md:px-8">
                    <x-ui.alert tone="warning" :title="__('This company is suspended')">
                        {{ __('Nothing new can be issued until it is reactivated. You can still read and export everything, and invoices already issued keep being filed.') }}
                        @if ($portalOrganization->suspension_reason)
                            {{ __('Reason: :reason', ['reason' => $portalOrganization->suspension_reason]) }}
                        @endif
                    </x-ui.alert>
                </div>
            @endif
            {{-- The portal had no way to reach its navigation on a phone. This
                 bar carries the toggle, and disappears at md where the sidebar
                 is a column again. --}}
            <div data-sticky-bar class="glass-bar sticky top-0 z-20 flex items-center gap-3 border-b border-line px-4 py-2 md:hidden">
                <button type="button" @click="toggle" :aria-expanded="expanded"
                        aria-controls="sidebar" aria-label="{{ __('Navigation') }}"
                        class="-ms-2 inline-flex min-h-11 min-w-11 items-center justify-center rounded-button text-muted transition-colors hover:bg-sunken hover:text-fg focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus">
                    <x-ui.icon name="menu" class="size-6" />
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

    <x-ui.strings />
    <x-ui.toasts />
    <x-ui.confirm-dialog />
</body>
</html>
