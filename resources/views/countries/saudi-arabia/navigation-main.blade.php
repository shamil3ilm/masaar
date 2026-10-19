{{-- What was sent to ZATCA and the certificate it was signed with, after Invoices (Presentation::navigation). --}}
<x-ui.nav-link :href="route('portal.submissions')" :active="request()->routeIs('portal.submissions')">
    <x-slot:icon><x-ui.icon name="document" /></x-slot:icon>
    {{ __('Submissions') }}
</x-ui.nav-link>
<x-ui.nav-link :href="route('portal.certificates')" :active="request()->routeIs('portal.certificates')">
    <x-slot:icon><x-ui.icon name="shield" /></x-slot:icon>
    {{ __('Certificates') }}
</x-ui.nav-link>
