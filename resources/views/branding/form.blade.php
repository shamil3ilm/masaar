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
        'primary' => [__('Primary'), __('Buttons, links and highlights.')],
        'navigation' => [__('Navigation'), __('The dark surfaces: sidebar, headers, the logo tile.')],
        'highlight' => [__('Highlight'), __('Accents used sparingly.')],
    ];
    $files = [
        'logo' => [__('Logo'), __('Remove the current logo')],
        'favicon' => [__('Favicon'), __('Remove the current favicon')],
    ];
@endphp

@if ($errors->any())
    <div class="mb-6 rounded border border-red-200 dark:border-red-800 bg-red-50 dark:bg-red-950/40 px-4 py-3 text-sm text-red-700 dark:text-red-300">
        <ul class="list-disc ps-5">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif

<form method="POST" action="{{ route($route.'.update') }}" enctype="multipart/form-data" class="mb-6 max-w-2xl">
    @csrf

    <div class="mb-6 rounded-lg bg-white dark:bg-gray-900 shadow">
        <div class="border-b p-4">
            <h3 class="font-semibold text-gray-800 dark:text-gray-100">{{ __('Name and mode') }}</h3>
        </div>
        <div class="space-y-4 p-4">
            <x-ui.field :label="__('Name shown beside the logo')">
                <x-ui.input type="text" name="name" maxlength="40"
                            placeholder="{{ $effective->name() }}"
                            value="{{ old('name', $stored->name) }}" />
                <span class="mt-1 block text-xs font-normal text-gray-500 dark:text-gray-400">{{ $inherits ? __('Left empty, the platform\'s name is used.') : __('Left empty, the shipped name is used.') }}</span>
            </x-ui.field>

            <x-ui.field :label="__('Mode')">
                <x-ui.select name="mode">
                    <option value="" @selected(old('mode', $stored->mode) === null)>{{ $inherits ? __('Inherit (the platform\'s)') : __('Inherit (the shipped)') }}</option>
                    <option value="light" @selected(old('mode', $stored->mode) === 'light')>{{ __('Light') }}</option>
                    <option value="dark" @selected(old('mode', $stored->mode) === 'dark')>{{ __('Dark') }}</option>
                    <option value="system" @selected(old('mode', $stored->mode) === 'system')>{{ __('Follow the device') }}</option>
                </x-ui.select>
            </x-ui.field>
        </div>
    </div>

    <div class="mb-6 rounded-lg bg-white dark:bg-gray-900 shadow">
        <div class="border-b p-4">
            <h3 class="font-semibold text-gray-800 dark:text-gray-100">{{ __('Colours') }}</h3>
            <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('Each colour sets a full range of shades for hover and tinted states.') }}</p>
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
                               class="rounded border-gray-300 dark:border-gray-700 text-teal-600 dark:text-teal-400 focus:ring-teal-600"
                               @checked(old("custom.$key", $own !== null ? '1' : '0') === '1')>
                        <span class="font-medium text-gray-700 dark:text-gray-200">{{ $label }}</span>
                    </label>
                    <x-ui.input type="color" name="colors[{{ $key }}]" value="{{ $shown }}"
                                aria-label="{{ __(':label colour', ['label' => $label]) }}" class="!mt-0 !w-20 cursor-pointer" />
                    <span class="text-xs text-gray-500 dark:text-gray-400">{{ $help }} {{ $inherits ? __('Unticked, the platform\'s colour applies.') : __('Unticked, the shipped colour applies.') }}</span>
                </div>
            @endforeach
        </div>
    </div>

    <div class="mb-6 rounded-lg bg-white dark:bg-gray-900 shadow">
        <div class="border-b p-4">
            <h3 class="font-semibold text-gray-800 dark:text-gray-100">{{ __('Logo and favicon') }}</h3>
            <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('PNG, JPEG, WebP or SVG, up to :size KB.', ['size' => \App\Domains\Branding\Services\BrandAssets::MAX_KILOBYTES]) }}
                @if ($inherits) {{ __('Your logo also appears at the top of the invoices you print.') }} @endif</p>
        </div>
        <div class="space-y-4 p-4">
            @foreach ($files as $kind => [$label, $removeLabel])
                <div>
                    <x-ui.field :label="$label">
                        <x-ui.input type="file" name="{{ $kind }}" accept="image/png,image/jpeg,image/webp,image/svg+xml" />
                    </x-ui.field>
                    @if ($assets[$kind])
                        <label class="mt-2 flex items-center gap-2 text-sm text-gray-600 dark:text-gray-300">
                            <input type="hidden" name="remove_{{ $kind }}" value="0">
                            <input type="checkbox" name="remove_{{ $kind }}" value="1"
                                   class="rounded border-gray-300 dark:border-gray-700 text-teal-600 dark:text-teal-400 focus:ring-teal-600">
                            {{ $removeLabel }}
                        </label>
                    @endif
                </div>
            @endforeach

            @if ($effective->logoUrl)
                <div class="rounded border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-950 p-4">
                    <p class="mb-2 text-xs text-gray-500 dark:text-gray-400">{{ __('Shown now') }}</p>
                    <img src="{{ $effective->logoUrl }}" alt="{{ __('Current logo') }}" height="48" class="h-12 w-auto">
                </div>
            @endif
        </div>
    </div>

    <x-ui.button>{{ __('Save branding') }}</x-ui.button>
</form>

<div class="mb-6 max-w-2xl rounded-lg bg-white dark:bg-gray-900 shadow">
    <div class="border-b p-4">
        <h3 class="font-semibold text-gray-800 dark:text-gray-100">{{ __('Theme as JSON') }}</h3>
        <p class="text-xs text-gray-500 dark:text-gray-400">
            {{ __('The whole theme in one file: name, mode, colours, and "shades" to pin any single step, such as') }}
            <code dir="ltr">"primary-600": "#0D9488"</code>. {{ __('Importing replaces the colours, name and mode; the logo and favicon stay.') }}
        </p>
    </div>
    <form method="POST" action="{{ route($route.'.import') }}" class="space-y-4 p-4">
        @csrf
        <x-ui.field :label="__('Theme JSON')">
            <x-ui.textarea name="theme_json" rows="12" class="font-mono text-xs" dir="ltr">{{ old('theme_json', $json) }}</x-ui.textarea>
        </x-ui.field>
        <div class="flex flex-wrap items-center gap-4">
            <x-ui.button>{{ __('Import') }}</x-ui.button>
            <x-ui.button variant="quiet" :href="route($route.'.export')">{{ __('Download theme.json') }}</x-ui.button>
        </div>
    </form>
</div>

<form method="POST" action="{{ route($route.'.reset') }}" class="max-w-2xl"
      data-confirm="{{ $inherits ? __('Reset the branding? Colours, name, logo and favicon go back to the platform\'s.') : __('Reset the branding? Colours, name, logo and favicon go back to the shipped.') }}">
    @csrf
    <x-ui.button variant="quiet">{{ $inherits ? __('Reset to the platform\'s branding') : __('Reset to the shipped branding') }}</x-ui.button>
</form>
