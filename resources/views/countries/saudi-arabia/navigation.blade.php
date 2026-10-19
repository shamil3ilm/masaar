{{-- ZATCA onboarding, among an administrator's links (Presentation::navigation). --}}
<x-ui.nav-link :href="route('portal.zatca.show')" :active="request()->routeIs('portal.zatca.show', 'portal.zatca.ccsid', 'portal.zatca.check', 'portal.zatca.production')">
    <x-slot:icon><x-ui.icon name="link" /></x-slot:icon>
    {{ __('ZATCA') }}
</x-ui.nav-link>
{{-- Invoices not reported within 24 hours, and the notices ZATCA asks for. --}}
<x-ui.nav-link :href="route('portal.zatca.late')" :active="request()->routeIs('portal.zatca.late*')">
    <x-slot:icon><x-ui.icon name="clock" /></x-slot:icon>
    {{ __('Late reporting') }}
</x-ui.nav-link>
{{-- Each device's certificates: status, validity, and what still waits to file under each. --}}
<x-ui.nav-link :href="route('portal.zatca.certificates')" :active="request()->routeIs('portal.zatca.certificates', 'portal.zatca.renew*')">
    <x-slot:icon><x-ui.icon name="shield" /></x-slot:icon>
    {{ __('Certificates') }}
</x-ui.nav-link>
