<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ \App\Http\Middleware\SetLocale::rtl() ? 'rtl' : 'ltr' }}" class="{{ $theme->htmlClass() }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', __('ZATCA Compliance Portal'))</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <x-theme-head />
</head>
<body class="bg-gray-50 dark:bg-gray-950 min-h-screen">
    <div x-data="drawer" class="flex">
        <div x-show="covering" @click="close" x-cloak
             class="fixed inset-0 z-30 bg-ink-950/50" aria-hidden="true"></div>

        <!-- Sidebar -->
        <aside id="sidebar" :class="panel"
               class="fixed inset-y-0 start-0 z-40 flex flex-col overflow-y-auto border-e bg-white dark:bg-gray-900 transition-transform duration-300 md:static md:z-auto md:min-h-screen">
            {{-- Which country's tagline and links to show. Not every screen passes the organization. --}}
            @php($navOrganization = $organization ?? app(\App\Domains\Organization\Services\TenantResolver::class)->getOrganization())
            <div class="border-b p-6">
                <x-logo :size="30" name name-class="text-base font-semibold tracking-tight text-ink-900 dark:text-gray-100" />
                <p class="mt-2 text-xs tracking-wide text-gray-400 dark:text-gray-500 uppercase">{{ $navOrganization?->country === 'AE' ? __('UAE E-invoicing Portal') : __('ZATCA Portal') }}</p>
                @if(isset($organization))
                <p class="mt-2 text-sm font-medium text-ink-900 dark:text-gray-100">{{ $organization->name }}</p>
                @endif
            </div>
            <nav class="flex-1 p-4">
                {{-- The active organization lives in the session; links carry no tenant identifier. --}}
                <a href="{{ route('portal.dashboard') }}"
                   class="flex items-center px-4 py-2 rounded-lg mb-1 {{ request()->routeIs('portal.dashboard') ? 'bg-teal-50 dark:bg-teal-950/40 font-medium text-teal-800 dark:text-teal-200' : 'text-gray-600 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-950' }}">
                    <svg class="w-5 h-5 me-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"></path></svg>
                    {{ __('Dashboard') }}
                </a>
                <a href="{{ route('portal.invoices.index') }}"
                   class="flex items-center px-4 py-2 rounded-lg mb-1 {{ request()->routeIs('portal.invoices.*') ? 'bg-teal-50 dark:bg-teal-950/40 font-medium text-teal-800 dark:text-teal-200' : 'text-gray-600 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-950' }}">
                    <svg class="w-5 h-5 me-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 14h6m-6-4h6m4 10H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v12a2 2 0 01-2 2z"></path></svg>
                    {{ __('Invoices') }}
                </a>
                {{-- A UAE organization's received e-invoices; nobody else has any. --}}
                @if (\App\Domains\Compliance\Countries\UnitedArabEmirates\Support\UaePortal::offers($navOrganization))
                <a href="{{ route('portal.received.index') }}"
                   class="flex items-center px-4 py-2 rounded-lg mb-1 {{ request()->routeIs('portal.received.*') ? 'bg-teal-50 dark:bg-teal-950/40 font-medium text-teal-800 dark:text-teal-200' : 'text-gray-600 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-950' }}">
                    <svg class="w-5 h-5 me-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4"></path></svg>
                    {{ __('Received invoices') }}
                </a>
                @endif
                <a href="{{ route('portal.customers.index') }}"
                   class="flex items-center px-4 py-2 rounded-lg mb-1 {{ request()->routeIs('portal.customers.*') ? 'bg-teal-50 dark:bg-teal-950/40 font-medium text-teal-800 dark:text-teal-200' : 'text-gray-600 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-950' }}">
                    <svg class="w-5 h-5 me-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path></svg>
                    {{ __('Customers') }}
                </a>
                <a href="{{ route('portal.products.index') }}"
                   class="flex items-center px-4 py-2 rounded-lg mb-1 {{ request()->routeIs('portal.products.*') ? 'bg-teal-50 dark:bg-teal-950/40 font-medium text-teal-800 dark:text-teal-200' : 'text-gray-600 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-950' }}">
                    <svg class="w-5 h-5 me-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path></svg>
                    {{ __('Products') }}
                </a>
                <a href="{{ route('portal.submissions') }}"
                   class="flex items-center px-4 py-2 rounded-lg mb-1 {{ request()->routeIs('portal.submissions') ? 'bg-teal-50 dark:bg-teal-950/40 font-medium text-teal-800 dark:text-teal-200' : 'text-gray-600 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-950' }}">
                    <svg class="w-5 h-5 me-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
                    {{ __('Submissions') }}
                </a>
                <a href="{{ route('portal.certificates') }}"
                   class="flex items-center px-4 py-2 rounded-lg mb-1 {{ request()->routeIs('portal.certificates') ? 'bg-teal-50 dark:bg-teal-950/40 font-medium text-teal-800 dark:text-teal-200' : 'text-gray-600 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-950' }}">
                    <svg class="w-5 h-5 me-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"></path></svg>
                    {{ __('Certificates') }}
                </a>
                <a href="{{ route('portal.reports') }}"
                   class="flex items-center px-4 py-2 rounded-lg mb-1 {{ request()->routeIs('portal.reports*') ? 'bg-teal-50 dark:bg-teal-950/40 font-medium text-teal-800 dark:text-teal-200' : 'text-gray-600 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-950' }}">
                    <svg class="w-5 h-5 me-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
                    {{ __('Reports') }}
                </a>
                <a href="{{ route('portal.support.index') }}"
                   class="flex items-center px-4 py-2 rounded-lg mb-1 {{ request()->routeIs('portal.support.*') ? 'bg-teal-50 dark:bg-teal-950/40 font-medium text-teal-800 dark:text-teal-200' : 'text-gray-600 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-950' }}">
                    <svg class="w-5 h-5 me-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z"></path></svg>
                    {{ __('Support') }}
                </a>
                {{-- Its administrators only; the page itself refuses anyone else. --}}
                @if (auth()->user()?->roleIn((string) request()->attributes->get(\App\Domains\Organization\Http\Middleware\PortalTenant::ORG_ID)) === 'admin')
                <a href="{{ route('portal.company.edit') }}"
                   class="flex items-center px-4 py-2 rounded-lg mb-1 {{ request()->routeIs('portal.company.*') ? 'bg-teal-50 dark:bg-teal-950/40 font-medium text-teal-800 dark:text-teal-200' : 'text-gray-600 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-950' }}">
                    <svg class="w-5 h-5 me-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"></path></svg>
                    {{ __('Company') }}
                </a>
                <a href="{{ route('portal.team.index') }}"
                   class="flex items-center px-4 py-2 rounded-lg mb-1 {{ request()->routeIs('portal.team.*') ? 'bg-teal-50 dark:bg-teal-950/40 font-medium text-teal-800 dark:text-teal-200' : 'text-gray-600 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-950' }}">
                    <svg class="w-5 h-5 me-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"></path></svg>
                    {{ __('Team') }}
                </a>
                {{-- A UAE organization does not onboard with ZATCA. --}}
                @if ($navOrganization?->country !== 'AE')
                <a href="{{ route('portal.zatca.show') }}"
                   class="flex items-center px-4 py-2 rounded-lg mb-1 {{ request()->routeIs('portal.zatca.*') ? 'bg-teal-50 dark:bg-teal-950/40 font-medium text-teal-800 dark:text-teal-200' : 'text-gray-600 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-950' }}">
                    <svg class="w-5 h-5 me-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"></path></svg>
                    {{ __('ZATCA') }}
                </a>
                @endif
                <a href="{{ route('portal.numbering.edit') }}"
                   class="flex items-center px-4 py-2 rounded-lg mb-1 {{ request()->routeIs('portal.numbering.*') ? 'bg-teal-50 dark:bg-teal-950/40 font-medium text-teal-800 dark:text-teal-200' : 'text-gray-600 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-950' }}">
                    <svg class="w-5 h-5 me-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 20l4-16m2 16l4-16M6 9h14M4 15h14"></path></svg>
                    {{ __('Numbering') }}
                </a>
                <a href="{{ route('portal.branding.edit') }}"
                   class="flex items-center px-4 py-2 rounded-lg mb-1 {{ request()->routeIs('portal.branding.*') ? 'bg-teal-50 dark:bg-teal-950/40 font-medium text-teal-800 dark:text-teal-200' : 'text-gray-600 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-950' }}">
                    <svg class="w-5 h-5 me-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21a4 4 0 01-4-4V5a2 2 0 012-2h4a2 2 0 012 2v12a4 4 0 01-4 4zm0 0h12a2 2 0 002-2v-4a2 2 0 00-2-2h-2.343M11 7.343l1.657-1.657a2 2 0 012.828 0l2.829 2.829a2 2 0 010 2.828l-8.486 8.485M7 17h.01"></path></svg>
                    {{ __('Branding') }}
                </a>
                <a href="{{ route('portal.billing') }}"
                   class="flex items-center px-4 py-2 rounded-lg mb-1 {{ request()->routeIs('portal.billing*') ? 'bg-teal-50 dark:bg-teal-950/40 font-medium text-teal-800 dark:text-teal-200' : 'text-gray-600 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-950' }}">
                    <svg class="w-5 h-5 me-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"></path></svg>
                    {{ __('Plan and billing') }}
                </a>
                @endif
            </nav>
            <div class="border-t bg-gray-50 dark:bg-gray-950 p-4">
                @auth
                <a href="{{ route('account.show') }}" class="block text-xs text-gray-500 dark:text-gray-400 truncate mb-2 hover:underline">{{ auth()->user()->email }}</a>
                <x-locale-switch class="mb-2 block" />
                <div class="flex items-center gap-3 mb-2">
                    <a href="{{ route('portal.switch') }}" class="text-xs text-teal-700 dark:text-teal-300 hover:text-teal-900 dark:hover:text-teal-200">{{ __('Switch organization') }}</a>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="text-xs text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200">{{ __('Sign out') }}</button>
                    </form>
                </div>
                @endauth
                <p class="text-xs text-gray-400 dark:text-gray-500">{{ __('Powered by Masaar') }}</p>
            </div>
        </aside>

        <!-- Main Content -->
        <main class="min-w-0 flex-1">
            <x-zatca-environment-banner />
            {{-- The portal had no way to reach its navigation on a phone: the
                 sidebar was a fixed 256px column of a 390px screen and there
                 was no toggle anywhere. This bar carries one, and disappears
                 at md where the sidebar is a column again. --}}
            <div class="flex items-center gap-3 border-b bg-white dark:bg-gray-900 px-4 py-3 md:hidden">
                <button type="button" @click="toggle" :aria-expanded="expanded"
                        aria-controls="sidebar" aria-label="{{ __('Navigation') }}"
                        class="-ms-2 inline-flex min-h-11 min-w-11 items-center justify-center rounded text-gray-500 dark:text-gray-400 transition-colors hover:text-gray-700 dark:hover:text-gray-200 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ink-900">
                    <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path></svg>
                </button>
                <x-logo :size="26" name name-class="text-sm font-semibold tracking-tight text-ink-900 dark:text-gray-100" />
            </div>

            <div class="p-4 md:p-8">
            {{-- In the layout, not in each page. Included by hand it reached
                 two of eleven portal views, and the pages that redirect with a
                 message were mostly not among them. --}}
                @include('portal.partials.flash')
                @if (isset($organization) && $organization->isPendingReview())
                    <div class="mb-6 rounded border border-amber-200 dark:border-amber-800 bg-amber-50 dark:bg-amber-950/40 px-4 py-3 text-sm text-amber-800 dark:text-amber-200">
                        <strong>{{ __('Your company is being reviewed.') }}</strong>
                        {{ __('You can complete its details and prepare invoices now. Connecting to e-invoicing and issuing open once it is approved.') }}
                    </div>
                @endif
                {{-- The organization's country switched off on the admin
                     console's Countries page (or off by default): the same
                     words for every country. --}}
                @if ($navOrganization && ! app(\App\Domains\Compliance\Engine\Services\JurisdictionResolver::class)->available($navOrganization))
                    <div role="status" class="mb-6 rounded border border-amber-200 dark:border-amber-800 bg-amber-50 dark:bg-amber-950/40 px-4 py-3 text-sm text-amber-800 dark:text-amber-200">
                        <strong>{{ __('E-invoicing in your country is switched off on this platform.') }}</strong>
                        {{ __('No new invoice can be issued until it is switched back on. Invoices already issued are still filed or sent, and you can view and download them.') }}
                    </div>
                @endif
                @yield('content')
            </div>
        </main>
    </div>

    <x-ui.confirm-dialog />
</body>
</html>
