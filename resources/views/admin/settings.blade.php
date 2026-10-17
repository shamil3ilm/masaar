@extends('layouts.admin')

@section('title', 'Settings')

@section('content')
<div class="mb-6">
    <h1 class="text-2xl font-semibold tracking-tight text-fg">Settings</h1>
    <p class="mt-1 max-w-3xl text-sm text-muted">
        What the dashboard treats as a problem, and when a certificate expiry is worth telling someone about.
        These took a release to change before.
    </p>
</div>

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
@endsection
