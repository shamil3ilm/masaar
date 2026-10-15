@extends('layouts.admin')

@section('title', 'Settings - Masaar Admin')

@section('content')
<div class="mb-6">
    <h2 class="text-2xl font-bold text-gray-800 dark:text-gray-100">Settings</h2>
    <p class="text-gray-500 dark:text-gray-400">
        What the dashboard treats as a problem, and when a certificate expiry is worth telling someone about.
        These took a release to change before.
    </p>
</div>

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
        <div class="mb-6 rounded-lg bg-white dark:bg-gray-900 shadow">
            <div class="border-b p-4">
                <h3 class="font-semibold text-gray-800 dark:text-gray-100">{{ $group }}</h3>
            </div>

            <div class="space-y-4 p-4">
                @foreach ($fields as $key => $definition)
                    @if ($definition['type'] === 'bool')
                        {{-- The hidden 0 first: an unticked checkbox posts
                             nothing at all, so without it turning a setting
                             off is indistinguishable from not sending it. --}}
                        <label class="flex items-start gap-3 text-sm">
                            <input type="hidden" name="{{ $key }}" value="0">
                            <input type="checkbox" name="{{ $key }}" value="1"
                                   class="mt-1 rounded border-gray-300 dark:border-gray-700 text-teal-600 dark:text-teal-400 focus:ring-teal-600"
                                   @checked(old($key, $settings->display($key)) === '1')>
                            <span>
                                <span class="font-medium text-gray-700 dark:text-gray-200">{{ $definition['label'] }}</span>
                                <span class="block text-xs text-gray-500 dark:text-gray-400">{{ $definition['help'] }}</span>
                            </span>
                        </label>
                    @else
                        <x-ui.field :label="$definition['label']">
                            @if ($definition['type'] === 'int')
                                <x-ui.input type="number" name="{{ $key }}"
                                            min="{{ $definition['min'] }}" max="{{ $definition['max'] }}" required
                                            value="{{ old($key, $settings->display($key)) }}" />
                            @else
                                <x-ui.input type="text" name="{{ $key }}" required
                                            value="{{ old($key, $settings->display($key)) }}" />
                            @endif

                            <span class="mt-1 block text-xs font-normal text-gray-500 dark:text-gray-400">{{ $definition['help'] }}</span>
                        </x-ui.field>
                    @endif
                @endforeach
            </div>
        </div>
    @endforeach

    <div class="flex items-center gap-3">
        <x-ui.button>Save settings</x-ui.button>
        <x-ui.button variant="quiet" :href="route('admin.settings')">Discard</x-ui.button>
    </div>
</form>
@endsection
