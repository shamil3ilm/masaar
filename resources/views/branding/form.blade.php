{{--
    The branding editor, for the platform's theme and for an organization's.

    $stored    what this scope sets itself (ThemeDefinition)
    $effective what pages actually show, inheritance included (ActiveTheme)
    $assets    whether this scope has its own logo and favicon
    $json      $stored as JSON, for the advanced editor
    $route     route name prefix: admin.branding or portal.branding
    $inherits  true for an organization, whose blanks fall back to the platform

    A colour picker always posts a colour, so each one has a box beside it:
    unticked, the colour is not set here and is inherited.
--}}
@php
    $labels = [
        'primary' => ['Primary', 'Buttons, links and highlights.'],
        'navigation' => ['Navigation', 'The dark surfaces: sidebar, headers, the logo tile.'],
        'highlight' => ['Highlight', 'Accents used sparingly.'],
    ];
    $fallback = $inherits ? 'the platform\'s' : 'the shipped';
@endphp

@if ($errors->any())
    <div class="mb-6 rounded border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
        <ul class="list-disc pl-5">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif

<form method="POST" action="{{ route($route.'.update') }}" enctype="multipart/form-data" class="mb-6 max-w-2xl">
    @csrf

    <div class="mb-6 rounded-lg bg-white shadow">
        <div class="border-b p-4">
            <h3 class="font-semibold text-gray-800">Name and mode</h3>
        </div>
        <div class="space-y-4 p-4">
            <x-ui.field label="Name shown beside the logo">
                <x-ui.input type="text" name="name" maxlength="40"
                            placeholder="{{ $effective->name() }}"
                            value="{{ old('name', $stored->name) }}" />
                <span class="mt-1 block text-xs font-normal text-gray-500">Left empty, {{ $fallback }} name is used.</span>
            </x-ui.field>

            <x-ui.field label="Mode">
                <x-ui.select name="mode">
                    <option value="" @selected(old('mode', $stored->mode) === null)>Inherit ({{ $fallback }})</option>
                    <option value="light" @selected(old('mode', $stored->mode) === 'light')>Light</option>
                    <option value="dark" @selected(old('mode', $stored->mode) === 'dark')>Dark</option>
                    <option value="system" @selected(old('mode', $stored->mode) === 'system')>Follow the device</option>
                </x-ui.select>
            </x-ui.field>
        </div>
    </div>

    <div class="mb-6 rounded-lg bg-white shadow">
        <div class="border-b p-4">
            <h3 class="font-semibold text-gray-800">Colours</h3>
            <p class="text-xs text-gray-500">Each colour sets a full range of shades for hover and tinted states.</p>
        </div>
        <div class="space-y-4 p-4">
            @foreach ($labels as $key => [$label, $help])
                @php
                    $own = $stored->colors[$key];
                    $shown = old("colors.$key", $own ?? $effective->definition->colors[$key]);
                @endphp
                <div class="flex flex-wrap items-center gap-4">
                    <label class="flex items-center gap-2 text-sm">
                        <input type="hidden" name="custom[{{ $key }}]" value="0">
                        <input type="checkbox" name="custom[{{ $key }}]" value="1"
                               class="rounded border-gray-300 text-teal-600 focus:ring-teal-600"
                               @checked(old("custom.$key", $own !== null ? '1' : '0') === '1')>
                        <span class="font-medium text-gray-700">{{ $label }}</span>
                    </label>
                    <x-ui.input type="color" name="colors[{{ $key }}]" value="{{ $shown }}"
                                aria-label="{{ $label }} colour" class="!mt-0 !w-20 cursor-pointer" />
                    <span class="text-xs text-gray-500">{{ $help }} Unticked, {{ $fallback }} colour applies.</span>
                </div>
            @endforeach
        </div>
    </div>

    <div class="mb-6 rounded-lg bg-white shadow">
        <div class="border-b p-4">
            <h3 class="font-semibold text-gray-800">Logo and favicon</h3>
            <p class="text-xs text-gray-500">PNG, JPEG, WebP or SVG, up to {{ \App\Domains\Branding\Services\BrandAssets::MAX_KILOBYTES }} KB.
                @if ($inherits) Your logo also appears at the top of the invoices you print. @endif</p>
        </div>
        <div class="space-y-4 p-4">
            @foreach (['logo' => 'Logo', 'favicon' => 'Favicon'] as $kind => $label)
                <div>
                    <x-ui.field :label="$label">
                        <x-ui.input type="file" name="{{ $kind }}" accept="image/png,image/jpeg,image/webp,image/svg+xml" />
                    </x-ui.field>
                    @if ($assets[$kind])
                        <label class="mt-2 flex items-center gap-2 text-sm text-gray-600">
                            <input type="hidden" name="remove_{{ $kind }}" value="0">
                            <input type="checkbox" name="remove_{{ $kind }}" value="1"
                                   class="rounded border-gray-300 text-teal-600 focus:ring-teal-600">
                            Remove the current {{ strtolower($label) }}
                        </label>
                    @endif
                </div>
            @endforeach

            @if ($effective->logoUrl)
                <div class="rounded border border-gray-200 bg-gray-50 p-4">
                    <p class="mb-2 text-xs text-gray-500">Shown now</p>
                    <img src="{{ $effective->logoUrl }}" alt="Current logo" height="48" class="h-12 w-auto">
                </div>
            @endif
        </div>
    </div>

    <x-ui.button>Save branding</x-ui.button>
</form>

<div class="mb-6 max-w-2xl rounded-lg bg-white shadow">
    <div class="border-b p-4">
        <h3 class="font-semibold text-gray-800">Theme as JSON</h3>
        <p class="text-xs text-gray-500">
            The whole theme in one file: name, mode, colours, and "shades" to pin any single step,
            such as <code>"primary-600": "#0D9488"</code>. Importing replaces the colours, name and mode; the logo and favicon stay.
        </p>
    </div>
    <form method="POST" action="{{ route($route.'.import') }}" class="space-y-4 p-4">
        @csrf
        <x-ui.field label="Theme JSON">
            <x-ui.textarea name="theme_json" rows="12" class="font-mono text-xs">{{ old('theme_json', $json) }}</x-ui.textarea>
        </x-ui.field>
        <div class="flex flex-wrap items-center gap-4">
            <x-ui.button>Import</x-ui.button>
            <x-ui.button variant="quiet" :href="route($route.'.export')">Download theme.json</x-ui.button>
        </div>
    </form>
</div>

<form method="POST" action="{{ route($route.'.reset') }}" class="max-w-2xl"
      data-confirm="Reset the branding? Colours, name, logo and favicon go back to {{ $fallback }}.">
    @csrf
    <x-ui.button variant="quiet">Reset to {{ $fallback }} branding</x-ui.button>
</form>
