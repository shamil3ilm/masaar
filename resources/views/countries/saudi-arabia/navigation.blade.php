{{-- ZATCA onboarding, among an administrator's links (Presentation::navigation). --}}
<x-ui.nav-link :href="route('portal.zatca.show')" :active="request()->routeIs('portal.zatca.*')">
    <x-slot:icon><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"></path></svg></x-slot:icon>
    {{ __('ZATCA') }}
</x-ui.nav-link>
