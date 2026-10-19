{{-- The console's Saudi panels, under the country's name (Presentation::NAV_CONSOLE). English, as the console is. --}}
<x-ui.nav-link :href="route('admin.alerts')" :active="request()->routeIs('admin.alerts')" collapsible>
    <x-slot:icon><x-ui.icon name="alert" /></x-slot:icon>
    Alerts
</x-ui.nav-link>
<x-ui.nav-link :href="route('admin.certificates')" :active="request()->routeIs('admin.certificates*')" collapsible>
    <x-slot:icon><x-ui.icon name="shield" /></x-slot:icon>
    Certificates
</x-ui.nav-link>
