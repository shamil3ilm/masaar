{{-- A UAE organization's received e-invoices, after Invoices (Presentation::navigation). --}}
<x-ui.nav-link :href="route('portal.received.index')" :active="request()->routeIs('portal.received.*')">
    <x-slot:icon><x-ui.icon name="inbox" /></x-slot:icon>
    {{ __('Received invoices') }}
</x-ui.nav-link>
