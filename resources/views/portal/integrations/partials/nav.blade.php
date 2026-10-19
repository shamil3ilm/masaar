{{--
    The Integrations pages, one link each: the one open is the primary
    action's colour, so where you are reads at a glance.
--}}
@php
    $pages = [
        'portal.integrations.index' => __('API keys'),
    ];
@endphp
<nav aria-label="{{ __('Integrations') }}" class="mb-6 flex flex-wrap gap-2">
    @foreach ($pages as $route => $label)
        <x-ui.button :href="route($route)" :variant="request()->routeIs($route) ? 'primary' : 'secondary'" size="sm"
                     :aria-current="request()->routeIs($route) ? 'page' : null">{{ $label }}</x-ui.button>
    @endforeach
</nav>
