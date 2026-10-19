{{-- ZATCA onboarding, among an administrator's links (Presentation::navigation). --}}
<x-ui.nav-link :href="route('portal.zatca.show')" :active="request()->routeIs('portal.zatca.show', 'portal.zatca.ccsid', 'portal.zatca.check', 'portal.zatca.production')">
    <x-slot:icon><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"></path></svg></x-slot:icon>
    {{ __('ZATCA') }}
</x-ui.nav-link>
{{-- Invoices not reported within 24 hours, and the notices ZATCA asks for. --}}
<x-ui.nav-link :href="route('portal.zatca.late')" :active="request()->routeIs('portal.zatca.late*')">
    <x-slot:icon><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg></x-slot:icon>
    {{ __('Late reporting') }}
</x-ui.nav-link>
{{-- Each device's certificates: status, validity, and what still waits to file under each. --}}
<x-ui.nav-link :href="route('portal.zatca.certificates')" :active="request()->routeIs('portal.zatca.certificates')">
    <x-slot:icon><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"></path></svg></x-slot:icon>
    {{ __('Certificates') }}
</x-ui.nav-link>
