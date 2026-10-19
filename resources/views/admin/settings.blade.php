@extends('layouts.admin')

@section('title', 'Settings')

@section('content')
<x-ui.page-header title="Settings" description="What the dashboard treats as a problem, and when a certificate expiry is worth telling someone about. These took a release to change before.">
    <x-slot:actions>
        <x-ui.button variant="secondary" :href="route('admin.business-types.index')">{{ __('Business types') }}</x-ui.button>
    </x-slot:actions>
</x-ui.page-header>

@admincan('platform.configure')
<form method="POST" action="{{ route('admin.settings.update') }}" class="max-w-2xl">
    @csrf

    @php
        // Grouped for reading. The group is part of each definition, so
        // adding a setting puts it in the right place without touching this.
        // preserveKeys, or the key becomes 0, 1, 2 and the field posts under
        // a number instead of its setting name.
        $groups = collect($definitions)->groupBy('group', preserveKeys: true);
    @endphp

    @foreach ($groups as $group => $fields)
        <div class="mb-6 rounded-card bg-surface border border-line shadow-card">
            <div class="border-b p-4">
                <h3 class="font-semibold text-fg">{{ $group }}</h3>
            </div>

            <div class="space-y-4 p-4">
                @foreach ($fields as $key => $definition)
                    @if ($definition['type'] === 'bool')
                        {{-- The hidden 0 first: an unticked checkbox posts
                             nothing at all, so without it turning a setting
                             off is indistinguishable from not sending it. --}}
                        <x-ui.switch name="{{ $key }}" value="1" off="0" :description="$definition['help']"
                                     :checked="old($key, $settings->display($key)) === '1'">
                            {{ $definition['label'] }}
                        </x-ui.switch>
                    @else
                        <x-ui.field :label="$definition['label']">
                            @if ($definition['type'] === 'int')
                                <x-ui.input type="number" name="{{ $key }}"
                                            min="{{ $definition['min'] }}" max="{{ $definition['max'] }}" required
                                            value="{{ old($key, $settings->display($key)) }}" />
                            @elseif ($definition['type'] === 'url')
                                {{-- Empty is a value here: it means none. --}}
                                <x-ui.input type="url" name="{{ $key }}" dir="ltr" placeholder="https://"
                                            value="{{ old($key, $settings->display($key)) }}" />
                            @elseif ($definition['type'] === 'email')
                                {{-- Empty is a value here too: the fallback the help names. --}}
                                <x-ui.input type="email" name="{{ $key }}" dir="ltr" placeholder="shamil3ilm@gmail.com"
                                            value="{{ old($key, $settings->display($key)) }}" />
                            @elseif ($definition['type'] === 'text')
                                <x-ui.textarea name="{{ $key }}" rows="3" maxlength="2000" dir="auto" required>{{ old($key, $settings->display($key)) }}</x-ui.textarea>
                            @else
                                <x-ui.input type="text" name="{{ $key }}" required
                                            value="{{ old($key, $settings->display($key)) }}" />
                            @endif

                            <span class="mt-1 block text-xs font-normal text-muted">{{ $definition['help'] }}</span>
                        </x-ui.field>
                    @endif
                @endforeach
            </div>
        </div>
    @endforeach

    <div class="flex items-center gap-3">
        <x-ui.button>Save settings</x-ui.button>
        <x-ui.button variant="ghost" :href="route('admin.settings')">Discard</x-ui.button>
    </div>
</form>
@endadmincan

{{-- The defaults every organization and person inherits until they set
     their own (Customizations). Read by everyone in the console; set with
     platform.configure. --}}
<div class="mt-10 max-w-3xl">
    <h2 class="text-lg font-semibold text-fg">{{ __('Defaults for everyone') }}</h2>
    <p class="mt-1 text-sm text-muted">{{ __('What every organization and person starts with. An organization can set its own, and a person their own, unless the platform locks it.') }}</p>

    <x-ui.card class="mt-4" flush>
        @foreach ($customizations as $resolved)
            @admincan('platform.configure')
                <x-customization :resolved="$resolved" :action="route('admin.customizations.update', $resolved->definition->key)" />
            @else
                <div class="border-t border-line p-5 first:border-t-0">
                    <p class="font-medium text-fg">{{ $resolved->definition->label }}</p>
                    <p class="mt-1 text-sm text-muted">{{ $resolved->definition->display($resolved->value) ?: __('Default') }} · {{ $resolved->sourceLabel() }}</p>
                </div>
            @endadmincan
        @endforeach
    </x-ui.card>
</div>
@endsection
