{{-- The Peppol network the UAE sends through, under the country's name (Presentation::NAV_CONSOLE). English, as the console is. --}}
<x-ui.nav-link :href="route('admin.peppol')" :active="request()->routeIs('admin.peppol*')" collapsible>
    <x-slot:icon><x-ui.icon name="exchange" /></x-slot:icon>
    Peppol
</x-ui.nav-link>
