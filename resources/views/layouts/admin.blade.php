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
    {{-- Every country the engine has, for its console panels and notices. --}}
    @php($consoleRegistry = app(\App\Domains\Compliance\Engine\Services\JurisdictionRegistry::class))
    @php($consoleCountries = array_map(fn (string $code) => $consoleRegistry->get($code), $consoleRegistry->registered()))
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
                    <x-slot:icon><x-ui.icon name="home" /></x-slot:icon>
                    Dashboard
                </x-ui.nav-link>
                <x-ui.nav-link :href="route('admin.organizations')" :active="request()->routeIs('admin.organizations*')" collapsible>
                    <x-slot:icon><x-ui.icon name="building" /></x-slot:icon>
                    Organizations
                </x-ui.nav-link>
                <x-ui.nav-link :href="route('admin.billing.renewals')" :active="request()->routeIs('admin.billing.renewals')" collapsible>
                    <x-slot:icon><x-ui.icon name="calendar" /></x-slot:icon>
                    Renewals
                </x-ui.nav-link>
                <x-ui.nav-link :href="route('admin.support.index')" :active="request()->routeIs('admin.support.*')" collapsible>
                    <x-slot:icon><x-ui.icon name="chat" /></x-slot:icon>
                    Support
                </x-ui.nav-link>
                <x-ui.nav-link :href="route('admin.board.index')" :active="request()->routeIs('admin.board.*')" collapsible>
                    <x-slot:icon><x-ui.icon name="inbox" /></x-slot:icon>
                    Feature board
                </x-ui.nav-link>
                <x-ui.nav-link :href="route('admin.users.index')" :active="request()->routeIs('admin.users.*')" collapsible>
                    <x-slot:icon><x-ui.icon name="users" /></x-slot:icon>
                    Users
                </x-ui.nav-link>
                <x-ui.nav-link :href="route('admin.queue')" :active="request()->routeIs('admin.queue')" collapsible>
                    <x-slot:icon><x-ui.icon name="queue" /></x-slot:icon>
                    Offline queue
                </x-ui.nav-link>
                <x-ui.nav-link :href="route('admin.logs')" :active="request()->routeIs('admin.logs')" collapsible>
                    <x-slot:icon><x-ui.icon name="document" /></x-slot:icon>
                    Logs
                </x-ui.nav-link>
                @admincan('audit.view')
                <x-ui.nav-link :href="route('admin.audit')" :active="request()->routeIs('admin.audit')" collapsible>
                    <x-slot:icon><x-ui.icon name="clipboard" /></x-slot:icon>
                    Audit log
                </x-ui.nav-link>
                @endadmincan
                {{-- With the number waiting for this admin: the in-app half of
                     telling approvers, beside the email. --}}
                @php($waitingApprovals = auth()->user() ? app(\App\Domains\Platform\Approvals\Approvals::class)->waitingFor(auth()->user())->count() : 0)
                <x-ui.nav-link :href="route('admin.approvals.index')" :active="request()->routeIs('admin.approvals.*') || request()->routeIs('admin.approval-policies.*')" collapsible>
                    <x-slot:icon><x-ui.icon name="approval" /></x-slot:icon>
                    {{ __('Approvals') }}
                    @if ($waitingApprovals > 0)
                        <x-ui.badge tone="warning" :label="(string) $waitingApprovals" />
                    @endif
                </x-ui.nav-link>
                <x-ui.nav-link :href="route('admin.licences.index')" :active="request()->routeIs('admin.licences.*')" collapsible>
                    <x-slot:icon><x-ui.icon name="key" /></x-slot:icon>
                    Licences
                </x-ui.nav-link>
                <x-ui.nav-link :href="route('admin.webhooks.index')" :active="request()->routeIs('admin.webhooks.*')" collapsible>
                    <x-slot:icon><x-ui.icon name="bolt" /></x-slot:icon>
                    Webhooks
                </x-ui.nav-link>
                {{-- The public website: its inbox, with the number nobody has
                     opened yet (support's, whose people's details they are),
                     and its words (website.manage). --}}
                @if (auth()->user()?->canAdmin('support.manage') || auth()->user()?->canAdmin('website.manage'))
                <p class="px-3 pb-1 pt-5 text-xs font-semibold uppercase tracking-wider text-subtle" x-show="labelled">{{ __('Website') }}</p>
                @endif
                @admincan('website.manage')
                <x-ui.nav-link :href="route('admin.website.sections.index')" :active="request()->routeIs('admin.website.sections.*')" collapsible>
                    <x-slot:icon><x-ui.icon name="layout" /></x-slot:icon>
                    {{ __('Home page') }}
                </x-ui.nav-link>
                <x-ui.nav-link :href="route('admin.website.testimonials.index')" :active="request()->routeIs('admin.website.testimonials.*')" collapsible>
                    <x-slot:icon><x-ui.icon name="quote" /></x-slot:icon>
                    {{ __('Testimonials') }}
                </x-ui.nav-link>
                <x-ui.nav-link :href="route('admin.website.seo.edit')" :active="request()->routeIs('admin.website.seo.*')" collapsible>
                    <x-slot:icon><x-ui.icon name="search" /></x-slot:icon>
                    {{ __('Search and sharing') }}
                </x-ui.nav-link>
                @endadmincan
                @admincan('support.manage')
                @php($unreadMessages = app(\App\Domains\Platform\Website\ContactInbox::class)->unread())
                <x-ui.nav-link :href="route('admin.website.messages.index')" :active="request()->routeIs('admin.website.messages.*')" collapsible>
                    <x-slot:icon><x-ui.icon name="mail" /></x-slot:icon>
                    {{ __('Messages') }}
                    @if ($unreadMessages > 0)
                        <x-ui.badge tone="warning" :label="(string) $unreadMessages" data-unread-messages />
                    @endif
                </x-ui.nav-link>
                @endadmincan
                <p class="px-3 pb-1 pt-5 text-xs font-semibold uppercase tracking-wider text-subtle" x-show="labelled">Finance</p>
                <x-ui.nav-link :href="route('admin.referrals.index')" :active="request()->routeIs('admin.referrals.*')" collapsible>
                    <x-slot:icon><x-ui.icon name="exchange" /></x-slot:icon>
                    Referrals
                </x-ui.nav-link>
                <p class="px-3 pb-1 pt-5 text-xs font-semibold uppercase tracking-wider text-subtle" x-show="labelled">Platform</p>
                <x-ui.nav-link :href="route('admin.kill-switches')" :active="request()->routeIs('admin.kill-switches*')" collapsible>
                    <x-slot:icon><x-ui.icon name="power" /></x-slot:icon>
                    Kill switches
                </x-ui.nav-link>
                <x-ui.nav-link :href="route('admin.settings')" :active="request()->routeIs('admin.settings*')" collapsible>
                    <x-slot:icon><x-ui.icon name="settings" /></x-slot:icon>
                    Settings
                </x-ui.nav-link>
                @admincan('platform.configure')
                <x-ui.nav-link :href="route('admin.countries')" :active="request()->routeIs('admin.countries*')" collapsible>
                    <x-slot:icon><x-ui.icon name="globe" /></x-slot:icon>
                    Countries
                </x-ui.nav-link>
                @endadmincan
                <x-ui.nav-link :href="route('admin.branding.edit')" :active="request()->routeIs('admin.branding*', 'admin.invoice-template*')" collapsible>
                    <x-slot:icon><x-ui.icon name="palette" /></x-slot:icon>
                    Branding
                </x-ui.nav-link>
                @admincan('admins.manage')
                <x-ui.nav-link :href="route('admin.admins.index')" :active="request()->routeIs('admin.admins.*')" collapsible>
                    <x-slot:icon><x-ui.icon name="user-shield" /></x-slot:icon>
                    Admins
                </x-ui.nav-link>
                @endadmincan
                <x-ui.nav-link :href="route('admin.design-system')" :active="request()->routeIs('admin.design-system')" collapsible>
                    <x-slot:icon><x-ui.icon name="grid" /></x-slot:icon>
                    Design system
                </x-ui.nav-link>
                {{-- Each country's own panels, under its name, from its module
                     (Presentation::NAV_CONSOLE): every registered country, on
                     or off, since an operator works on one before switching it on. --}}
                @foreach ($consoleCountries as $consoleCountry)
                    @if ($consoleLinks = $consoleCountry->presentation()->navigation(\App\Domains\Compliance\Engine\Contracts\Presentation::NAV_CONSOLE))
                        <p class="px-3 pb-1 pt-5 text-xs font-semibold uppercase tracking-wider text-subtle" x-show="labelled">{{ $consoleCountry->name() }}</p>
                        @include($consoleLinks)
                    @endif
                @endforeach
            </nav>
        </aside>

        {{-- min-w-0 is what makes the table's scroll area work at all. A flex
             child defaults to min-width:auto, so it refuses to shrink below
             its content: the widest table pushed main wider than the screen,
             the page itself scrolled sideways, and overflow-x-auto never had
             anything to do. --}}
        <main class="min-w-0 flex-1">
            {{-- Each country's notice, if it has one (Presentation::notice()). --}}
            @foreach ($consoleCountries as $consoleCountry)
                @if ($consoleNotice = $consoleCountry->presentation()->notice())
                    @include($consoleNotice)
                @endif
            @endforeach
            <header data-sticky-bar class="glass-bar sticky top-0 z-20 border-b border-line">
                <div class="flex items-center justify-between gap-3 px-4 py-2 md:px-6">
                    <button type="button" @click="toggle" :aria-expanded="expanded"
                            aria-controls="sidebar" aria-label="Navigation"
                            class="-ms-2 inline-flex min-h-11 min-w-11 items-center justify-center rounded-button text-muted transition-colors hover:bg-sunken hover:text-fg focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus">
                        <x-ui.icon name="menu" class="size-6" />
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
    <x-ui.strings />
    <x-ui.toasts />
    <x-ui.confirm-dialog />

    @stack('scripts')
</body>
</html>
