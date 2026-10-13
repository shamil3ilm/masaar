@extends('layouts.admin')

@section('title', 'Kill Switches - Masaar Admin')

@section('content')
{{--
    One control per switch, and the record of who last used it.

    KillSwitch has been able to stop issuance, submission, clearance,
    reporting and signing since it was written, and the only way to reach it
    was a tinker prompt on a production host. It has always recorded who
    enabled a switch, when, why and until when; none of that was readable.

    Every control is a POST behind data-confirm. The listener is bundled in
    resources/js/confirm.js, because the content policy allows no inline
    handler - and a blocked handler would not stop the form, only the asking.
--}}
@php
    $labels = [
        'issuance' => ['Issuance', 'Signing a new invoice, allocating its counter and writing its chain entry.'],
        'submission' => ['Submission', 'Sending anything to ZATCA at all.'],
        'clearance' => ['Clearance', 'Standard B2B invoices, which must be cleared before the buyer may have them.'],
        'reporting' => ['Reporting', 'Simplified B2C invoices, due within twenty-four hours of issuance.'],
        'signing' => ['Signing', 'The cryptographic stamp itself.'],
        'offline_mode' => ['Forced offline', 'Queue everything instead of attempting the authority.'],
    ];
@endphp

<div class="mb-6 flex flex-wrap items-start justify-between gap-4">
    <div>
        <h2 class="text-2xl font-bold text-gray-800">Kill switches</h2>
        <p class="text-gray-500">
            Platform-wide. Each one stops that step for every taxpayer, takes effect on the next
            request, and is recorded with the reason given for it.
        </p>
    </div>

    @if ($status['is_fully_operational'])
        <form method="POST" action="{{ route('admin.kill-switches.stop') }}" class="flex items-end gap-2"
              data-confirm="Stop everything? No invoice will be issued, signed or filed for any taxpayer until you resume.">
            @csrf
            <div>
                <label for="stop-reason" class="mb-1 block text-xs font-medium text-gray-600">Reason</label>
                <input id="stop-reason" name="reason" type="text" required minlength="5" maxlength="500"
                       value="{{ old('reason') }}" placeholder="Why everything is stopping"
                       class="w-72 rounded border border-gray-300 px-3 py-2 text-sm focus:border-teal-600 focus:ring-1 focus:ring-teal-600">
            </div>
            <button type="submit"
                    class="min-h-11 rounded bg-red-600 px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-red-700">
                Stop everything
            </button>
        </form>
    @else
        <form method="POST" action="{{ route('admin.kill-switches.resume') }}"
              data-confirm="Resume everything? Every switch currently on will be turned off.">
            @csrf
            <button type="submit"
                    class="min-h-11 rounded bg-teal-600 px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-teal-700">
                Resume everything
            </button>
        </form>
    @endif
</div>

@if ($status['is_fully_operational'])
    <div class="mb-6 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">
        Nothing is stopped. Every step is running for every taxpayer.
    </div>
@else
    <div class="mb-6 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
        <strong>{{ count($status['long_running_switches']) > 0 ? 'Stopped, and for a while.' : 'Something is stopped.' }}</strong>
        Taxpayers are being refused the steps listed below.
        @if (count($status['long_running_switches']) > 0)
            {{ count($status['long_running_switches']) }}
            {{ \Illuminate\Support\Str::plural('switch', count($status['long_running_switches'])) }}
            {{ count($status['long_running_switches']) === 1 ? 'has' : 'have' }}
            been on long enough to have raised an alert.
        @endif
    </div>
@endif

<div class="space-y-4">
    @foreach ($status['switches'] as $switch => $state)
        @php
            [$name, $description] = $labels[$switch] ?? [ucfirst(str_replace('_', ' ', $switch)), ''];
            $info = $state['info'];
        @endphp

        <div class="rounded-lg bg-white px-6 py-5 shadow">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="min-w-0">
                    <div class="flex items-center gap-3">
                        <h3 class="text-lg font-semibold text-gray-800">{{ $name }}</h3>

                        <x-ui.badge :tone="$state['enabled'] ? 'danger' : 'success'"
                            :label="$state['enabled'] ? 'Stopped' : 'Running'" />
                    </div>

                    <p class="mt-1 text-sm text-gray-500">{{ $description }}</p>

                    @if ($state['enabled'] && $info)
                        <dl class="mt-3 grid gap-x-6 gap-y-1 text-sm sm:grid-cols-2">
                            <div class="flex gap-2">
                                <dt class="text-gray-500">Reason</dt>
                                <dd class="text-gray-800">{{ $info['reason'] ?? 'none given' }}</dd>
                            </div>
                            <div class="flex gap-2">
                                <dt class="text-gray-500">Stopped by</dt>
                                <dd class="text-gray-800">{{ $info['enabled_by'] ?? 'unknown' }}</dd>
                            </div>
                            <div class="flex gap-2">
                                <dt class="text-gray-500">Since</dt>
                                <dd class="text-gray-800">{{ $info['enabled_at'] ?? 'unknown' }}</dd>
                            </div>
                            <div class="flex gap-2">
                                <dt class="text-gray-500">Until</dt>
                                <dd class="text-gray-800">
                                    {{-- No expiry is the dangerous case, so it says so rather than
                                         showing an empty cell. --}}
                                    {{ $state['is_time_boxed'] ? $state['expires_at'] : 'turned off by hand' }}
                                </dd>
                            </div>
                        </dl>
                    @endif
                </div>

                <div class="shrink-0">
                    @if ($state['enabled'])
                        <form method="POST" action="{{ route('admin.kill-switches.disable') }}"
                              data-confirm="Resume {{ strtolower($name) }}? Taxpayers will be able to do this again immediately.">
                            @csrf
                            <input type="hidden" name="switch" value="{{ $switch }}">
                            <button type="submit"
                                    class="min-h-11 rounded border border-teal-700 px-4 py-2 text-sm font-semibold text-teal-700 transition-colors hover:bg-teal-50">
                                Resume
                            </button>
                        </form>
                    @else
                        <form method="POST" action="{{ route('admin.kill-switches.enable') }}"
                              class="flex flex-wrap items-end justify-end gap-2"
                              data-confirm="Stop {{ strtolower($name) }} for every taxpayer?">
                            @csrf
                            <input type="hidden" name="switch" value="{{ $switch }}">

                            <div>
                                <label for="reason-{{ $switch }}" class="mb-1 block text-xs font-medium text-gray-600">
                                    Reason
                                </label>
                                <input id="reason-{{ $switch }}" name="reason" type="text" required
                                       minlength="5" maxlength="500" placeholder="Why this is stopping"
                                       class="w-56 rounded border border-gray-300 px-3 py-2 text-sm focus:border-teal-600 focus:ring-1 focus:ring-teal-600">
                            </div>

                            <div>
                                <label for="minutes-{{ $switch }}" class="mb-1 block text-xs font-medium text-gray-600">
                                    Minutes
                                </label>
                                <input id="minutes-{{ $switch }}" name="minutes" type="number" min="1"
                                       max="{{ $maxMinutes }}" placeholder="optional"
                                       class="w-28 rounded border border-gray-300 px-3 py-2 text-sm focus:border-teal-600 focus:ring-1 focus:ring-teal-600">
                            </div>

                            <button type="submit"
                                    class="min-h-11 rounded bg-red-600 px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-red-700">
                                Stop
                            </button>
                        </form>

                        <p class="mt-1 text-right text-xs text-gray-400">
                            Leave minutes empty and it stays on until someone turns it off.
                        </p>
                    @endif
                </div>
            </div>
        </div>
    @endforeach
</div>
@endsection
