@extends('layouts.admin')

@section('title', 'Countries')

@section('content')
{{--
    One card per country the platform knows, all with the same switch.

    A country's switch wins over its default (ZATCA_ENABLED for Saudi Arabia,
    PEPPOL_AE_ENABLED for the UAE). Off, nothing new is issued there: the
    portal says so, the API answers 422 JURISDICTION_UNAVAILABLE and sign-up
    stops offering it. What was already issued keeps being filed or sent.

    Every control is a POST behind data-confirm, read by the bundled listener
    (resources/js/confirm.js) - the content policy allows no inline handler.
--}}
<div class="mb-6">
    <h2 class="text-2xl font-bold text-gray-800 dark:text-gray-100">Countries</h2>
    <p class="text-gray-500 dark:text-gray-400">
        Where the platform issues e-invoices. Switching a country off stops new issuing there on the next
        request and is recorded with who did it; invoices already issued keep being filed or sent.
    </p>
</div>

<div class="space-y-4">
    @foreach ($countries as $country)
        @php
            $switch = $country['switch'];
            $count = $country['organizations'];
            $organizations = $count.' '.\Illuminate\Support\Str::plural('organization', $count);
            $offWarning = 'Switch off '.$country['name'].'? '
                .($count > 0 ? $organizations.' registered there will stop issuing invoices. ' : 'No organization is registered there yet. ')
                .($country['billing'] ? 'The platform\'s billing organization is in '.$country['name'].', so platform billing invoices stop too. ' : '')
                .'Invoices already issued keep being filed or sent.';
        @endphp

        <div class="rounded-lg bg-white dark:bg-gray-900 px-6 py-5 shadow">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="min-w-0">
                    <div class="flex items-center gap-3">
                        <h3 class="text-lg font-semibold text-gray-800 dark:text-gray-100">{{ $country['name'] }}</h3>
                        <span class="text-sm text-gray-500 dark:text-gray-400">{{ $country['code'] }}</span>
                        <x-ui.badge :tone="$country['enabled'] ? 'success' : 'neutral'"
                            :label="$country['enabled'] ? 'On' : 'Off'" />
                    </div>

                    <dl class="mt-3 grid gap-x-6 gap-y-1 text-sm sm:grid-cols-2">
                        <div class="flex gap-2">
                            <dt class="text-gray-500 dark:text-gray-400">E-invoicing</dt>
                            <dd class="text-gray-800 dark:text-gray-100">{{ $country['system'] }}</dd>
                        </div>
                        <div class="flex gap-2">
                            <dt class="text-gray-500 dark:text-gray-400">Organizations</dt>
                            <dd class="text-gray-800 dark:text-gray-100">{{ $count }}</dd>
                        </div>
                        <div class="flex gap-2">
                            <dt class="text-gray-500 dark:text-gray-400">Set by</dt>
                            <dd class="text-gray-800 dark:text-gray-100">
                                @if ($switch)
                                    Admin switch, {{ $switch['by'] ?? 'unknown' }} at {{ $switch['at'] ?? 'unknown' }}
                                @else
                                    Default (deployment configuration)
                                @endif
                            </dd>
                        </div>
                        @foreach ($country['readiness'] as $label => $value)
                            <div class="flex gap-2">
                                <dt class="text-gray-500 dark:text-gray-400">{{ $label }}</dt>
                                <dd class="text-gray-800 dark:text-gray-100">{{ $value }}</dd>
                            </div>
                        @endforeach
                    </dl>

                    @if ($country['billing'])
                        <p class="mt-3 text-sm text-amber-700 dark:text-amber-300">
                            The platform's billing organization is registered here: platform billing invoices are issued in this country.
                        </p>
                    @endif
                </div>

                {{-- Every admin can see which countries are on; only one whose
                     role may configure the platform can switch one. --}}
                @admincan('platform.configure')
                <div class="w-full sm:w-auto sm:shrink-0">
                    @if ($country['enabled'])
                        <form method="POST" action="{{ route('admin.countries.update') }}"
                              data-confirm-tone="danger" data-confirm-action="Switch off"
                              data-confirm="{{ $offWarning }}">
                            @csrf
                            <input type="hidden" name="country" value="{{ $country['code'] }}">
                            <input type="hidden" name="enabled" value="0">
                            <x-ui.button variant="danger">Switch off</x-ui.button>
                        </form>
                    @else
                        <form method="POST" action="{{ route('admin.countries.update') }}"
                              data-confirm-action="Switch on"
                              data-confirm="Switch on {{ $country['name'] }}? Its organizations can issue again, and sign-up offers it.">
                            @csrf
                            <input type="hidden" name="country" value="{{ $country['code'] }}">
                            <input type="hidden" name="enabled" value="1">
                            <x-ui.button>Switch on</x-ui.button>
                        </form>
                    @endif
                </div>
                @endadmincan
            </div>
        </div>
    @endforeach
</div>
@endsection
