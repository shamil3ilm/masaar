<!DOCTYPE html>
<html lang="en" class="{{ $theme->htmlClass() }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    {{-- The page's own title, then the platform's name from Branding. --}}
    <title>@hasSection('title')@yield('title') - @endif{{ $theme->name() }} Admin</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <x-theme-head />
</head>
<body class="min-h-screen bg-ground text-fg">
    <a href="#content" class="sr-only focus:not-sr-only focus:fixed focus:start-4 focus:top-4 focus:z-50 focus:rounded-button focus:bg-surface focus:px-4 focus:py-3 focus:shadow-raised">Skip to content</a>

    <div x-data="sidebar" class="flex">
        {{-- Covers the page only while the drawer is out, which is only ever
             below md. Closing on a tap is the gesture people expect from a
             drawer, and it is the only way out on a screen with no room for
             the toggle beside it. --}}
        <div x-show="covering" @click="close" x-cloak
             class="fixed inset-0 z-30 bg-ink-950/50" aria-hidden="true"></div>

        {{-- The same sidebar as the portal's, so the console and the portal
             are one product; the console says which it is under the mark. --}}
        <aside id="sidebar" :class="panel"
               class="fixed inset-y-0 start-0 z-40 flex flex-col overflow-y-auto overflow-x-hidden border-e border-line bg-surface transition-transform duration-300 md:sticky md:top-0 md:z-auto md:h-screen md:transition-all">
            <div class="flex items-center gap-2.5 border-b border-line px-4 py-4">
                <a href="{{ route('admin.dashboard') }}" class="inline-flex shrink-0 rounded" aria-label="{{ $theme->name() }} Admin">
                    <x-logo :size="32" />
                </a>
                <span class="min-w-0" x-show="labelled">
                    <span class="block truncate text-base font-semibold tracking-tight">{{ $theme->name() }}</span>
                    <span class="block text-xs font-medium uppercase tracking-wide text-subtle">Admin console</span>
                </span>
            </div>
            <nav class="flex-1 space-y-1 p-3" aria-label="Console">
                <x-ui.nav-link :href="route('admin.dashboard')" :active="request()->routeIs('admin.dashboard')" collapsible>
                    <x-slot:icon><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"></path></svg></x-slot:icon>
                    Dashboard
                </x-ui.nav-link>
                <x-ui.nav-link :href="route('admin.organizations')" :active="request()->routeIs('admin.organizations*')" collapsible>
                    <x-slot:icon><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"></path></svg></x-slot:icon>
                    Organizations
                </x-ui.nav-link>
                <x-ui.nav-link :href="route('admin.billing.renewals')" :active="request()->routeIs('admin.billing.renewals')" collapsible>
                    <x-slot:icon><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg></x-slot:icon>
                    Renewals
                </x-ui.nav-link>
                <x-ui.nav-link :href="route('admin.support.index')" :active="request()->routeIs('admin.support.*')" collapsible>
                    <x-slot:icon><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z"></path></svg></x-slot:icon>
                    Support
                </x-ui.nav-link>
                <x-ui.nav-link :href="route('admin.users.index')" :active="request()->routeIs('admin.users.*')" collapsible>
                    <x-slot:icon><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"></path></svg></x-slot:icon>
                    Users
                </x-ui.nav-link>
                <x-ui.nav-link :href="route('admin.queue')" :active="request()->routeIs('admin.queue')" collapsible>
                    <x-slot:icon><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M4 6h16M4 10h16M4 14h16M4 18h16"></path></svg></x-slot:icon>
                    Offline queue
                </x-ui.nav-link>
                <x-ui.nav-link :href="route('admin.logs')" :active="request()->routeIs('admin.logs')" collapsible>
                    <x-slot:icon><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg></x-slot:icon>
                    Logs
                </x-ui.nav-link>
                @admincan('audit.view')
                <x-ui.nav-link :href="route('admin.audit')" :active="request()->routeIs('admin.audit')" collapsible>
                    <x-slot:icon><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"></path></svg></x-slot:icon>
                    Audit log
                </x-ui.nav-link>
                @endadmincan
                {{-- With the number waiting for this admin: the in-app half of
                     telling approvers, beside the email. --}}
                @php($waitingApprovals = auth()->user() ? app(\App\Domains\Platform\Approvals\Approvals::class)->waitingFor(auth()->user())->count() : 0)
                <x-ui.nav-link :href="route('admin.approvals.index')" :active="request()->routeIs('admin.approvals.*') || request()->routeIs('admin.approval-policies.*')" collapsible>
                    <x-slot:icon><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M9 12l2 2 4-4M7 4h10a2 2 0 012 2v14l-7-3-7 3V6a2 2 0 012-2z"></path></svg></x-slot:icon>
                    {{ __('Approvals') }}
                    @if ($waitingApprovals > 0)
                        <x-ui.badge tone="warning" :label="(string) $waitingApprovals" />
                    @endif
                </x-ui.nav-link>
                <x-ui.nav-link :href="route('admin.licences.index')" :active="request()->routeIs('admin.licences.*')" collapsible>
                    <x-slot:icon><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"></path></svg></x-slot:icon>
                    Licences
                </x-ui.nav-link>
                <x-ui.nav-link :href="route('admin.alerts')" :active="request()->routeIs('admin.alerts')" collapsible>
                    <x-slot:icon><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg></x-slot:icon>
                    Alerts
                </x-ui.nav-link>
                <x-ui.nav-link :href="route('admin.webhooks.index')" :active="request()->routeIs('admin.webhooks.*')" collapsible>
                    <x-slot:icon><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M13 10V3L4 14h7v7l9-11h-7z"></path></svg></x-slot:icon>
                    Webhooks
                </x-ui.nav-link>
                {{-- The public website's inbox, with the number nobody has
                     opened yet. Its people's details are support's to keep. --}}
                @admincan('support.manage')
                @php($unreadMessages = app(\App\Domains\Platform\Website\ContactInbox::class)->unread())
                <p class="px-3 pb-1 pt-5 text-xs font-semibold uppercase tracking-wider text-subtle" x-show="labelled">{{ __('Website') }}</p>
                <x-ui.nav-link :href="route('admin.website.messages.index')" :active="request()->routeIs('admin.website.messages.*')" collapsible>
                    <x-slot:icon><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"></path></svg></x-slot:icon>
                    {{ __('Messages') }}
                    @if ($unreadMessages > 0)
                        <x-ui.badge tone="warning" :label="(string) $unreadMessages" data-unread-messages />
                    @endif
                </x-ui.nav-link>
                @endadmincan
                <p class="px-3 pb-1 pt-5 text-xs font-semibold uppercase tracking-wider text-subtle" x-show="labelled">Platform</p>
                <x-ui.nav-link :href="route('admin.kill-switches')" :active="request()->routeIs('admin.kill-switches*')" collapsible>
                    <x-slot:icon><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M18.364 5.636a9 9 0 11-12.728 0M12 3v9"></path></svg></x-slot:icon>
                    Kill switches
                </x-ui.nav-link>
                <x-ui.nav-link :href="route('admin.certificates')" :active="request()->routeIs('admin.certificates*')" collapsible>
                    <x-slot:icon><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"></path></svg></x-slot:icon>
                    Certificates
                </x-ui.nav-link>
                <x-ui.nav-link :href="route('admin.settings')" :active="request()->routeIs('admin.settings*')" collapsible>
                    <x-slot:icon><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path></svg></x-slot:icon>
                    Settings
                </x-ui.nav-link>
                @admincan('platform.configure')
                <x-ui.nav-link :href="route('admin.countries')" :active="request()->routeIs('admin.countries*')" collapsible>
                    <x-slot:icon><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M3.055 11H5a2 2 0 012 2v1a2 2 0 002 2 2 2 0 012 2v2.945M8 3.935V5.5A2.5 2.5 0 0010.5 8h.5a2 2 0 012 2 2 2 0 104 0 2 2 0 012-2h1.064M15 20.488V18a2 2 0 012-2h3.064M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg></x-slot:icon>
                    Countries
                </x-ui.nav-link>
                @endadmincan
                <x-ui.nav-link :href="route('admin.branding.edit')" :active="request()->routeIs('admin.branding*')" collapsible>
                    <x-slot:icon><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M7 21a4 4 0 01-4-4V5a2 2 0 012-2h4a2 2 0 012 2v12a4 4 0 01-4 4zm0 0h12a2 2 0 002-2v-4a2 2 0 00-2-2h-2.343M11 7.343l1.657-1.657a2 2 0 012.828 0l2.829 2.829a2 2 0 010 2.828l-8.486 8.485M7 17h.01"></path></svg></x-slot:icon>
                    Branding
                </x-ui.nav-link>
                @admincan('admins.manage')
                <x-ui.nav-link :href="route('admin.admins.index')" :active="request()->routeIs('admin.admins.*')" collapsible>
                    <x-slot:icon><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"></path></svg></x-slot:icon>
                    Admins
                </x-ui.nav-link>
                @endadmincan
                <x-ui.nav-link :href="route('admin.design-system')" :active="request()->routeIs('admin.design-system')" collapsible>
                    <x-slot:icon><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M4 5a1 1 0 011-1h4a1 1 0 011 1v4a1 1 0 01-1 1H5a1 1 0 01-1-1V5zm10 0a1 1 0 011-1h4a1 1 0 011 1v4a1 1 0 01-1 1h-4a1 1 0 01-1-1V5zM4 15a1 1 0 011-1h4a1 1 0 011 1v4a1 1 0 01-1 1H5a1 1 0 01-1-1v-4zm10 3a3 3 0 106 0 3 3 0 00-6 0z"></path></svg></x-slot:icon>
                    Design system
                </x-ui.nav-link>
            </nav>
        </aside>

        {{-- min-w-0 is what makes the table's scroll area work at all. A flex
             child defaults to min-width:auto, so it refuses to shrink below
             its content: the widest table pushed main wider than the screen,
             the page itself scrolled sideways, and overflow-x-auto never had
             anything to do. --}}
        <main class="min-w-0 flex-1">
            <x-zatca-environment-banner />
            <header class="sticky top-0 z-20 border-b border-line bg-ground/90 backdrop-blur">
                <div class="flex items-center justify-between gap-3 px-4 py-2 md:px-6">
                    <button type="button" @click="toggle" :aria-expanded="expanded"
                            aria-controls="sidebar" aria-label="Navigation"
                            class="-ms-2 inline-flex min-h-11 min-w-11 items-center justify-center rounded-button text-muted transition-colors hover:bg-sunken hover:text-fg focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus">
                        <svg class="size-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path></svg>
                    </button>
                    @auth
                        <x-ui.menu :label="'Account: '.auth()->user()->email">
                            <x-slot:trigger>
                                <span class="flex size-7 items-center justify-center rounded-full bg-highlight text-xs font-semibold text-ink-900" aria-hidden="true">{{ strtoupper(mb_substr(auth()->user()->email, 0, 1)) }}</span>
                                <span class="hidden max-w-56 truncate sm:inline">{{ auth()->user()->email }}</span>
                            </x-slot:trigger>
                            <x-ui.menu-item :href="route('account.show')">Your account</x-ui.menu-item>
                            <form method="POST" action="{{ route('logout') }}">
                                @csrf
                                <x-ui.menu-item>Sign out</x-ui.menu-item>
                            </form>
                        </x-ui.menu>
                    @endauth
                </div>
            </header>

            <div id="content" class="space-y-6 p-4 md:p-8">
                <x-ui.flash />
                @php($ownershipOffer = auth()->user() ? app(\App\Domains\Auth\Services\PlatformOwnership::class)->pending() : null)
                @if ($ownershipOffer && $ownershipOffer->to_user_id === auth()->id())
                    <x-ui.alert tone="warning" :title="__('You are offered the platform\'s ownership')" class="mb-6">
                        <p>{{ __(':email offers you ownership of the platform. Accept, and you become its one owner; they step down.', ['email' => $ownershipOffer->from?->email]) }}</p>
                        <form method="POST" action="{{ route('admin.ownership.accept', $ownershipOffer->id) }}" class="mt-3"
                              data-confirm-action="{{ __('Accept ownership') }}" data-confirm="{{ __('Become the platform\'s owner?') }}"
                              data-confirm-detail="{{ __('You become its one owner at once, and :email steps down to the role they chose.', ['email' => $ownershipOffer->from?->email]) }}">
                            @csrf
                            <x-ui.button variant="primary" size="sm">{{ __('Accept ownership') }}</x-ui.button>
                        </form>
                    </x-ui.alert>
                @endif
                <div>
                    @yield('content')
                </div>
            </div>
        </main>
    </div>
    <x-ui.confirm-dialog />

    @stack('scripts')
</body>
</html>
