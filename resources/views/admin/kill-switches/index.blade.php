@extends('layouts.admin')

@section('title', 'Kill Switches')

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
        <h1 class="text-2xl font-semibold tracking-tight text-fg">Kill switches</h1>
        <p class="mt-1 max-w-3xl text-sm text-muted">
            Platform-wide. Each one stops that step for every taxpayer, takes effect on the next
            request, and is recorded with the reason given for it.
        </p>
    </div>

    @if ($status['is_fully_operational'])
        @admincan('platform.configure')
        <form method="POST" action="{{ route('admin.kill-switches.stop') }}" class="flex flex-wrap items-end gap-2"
              data-confirm-tone="danger" data-confirm-action="Stop everything"
              data-confirm="Stop everything?"
              data-confirm-detail="No invoice will be issued, signed or filed for any taxpayer until you resume.">
            @csrf
            <div>
                <label for="stop-reason" class="mb-1 block text-xs font-medium text-muted">Reason</label>
                <x-ui.input id="stop-reason" name="reason" type="text" required minlength="5" maxlength="500"
                       value="{{ old('reason') }}" placeholder="Why everything is stopping" class="sm:w-72" />
            </div>
            <x-ui.button variant="danger">Stop everything</x-ui.button>
        </form>
        @endadmincan
    @else
        @admincan('platform.configure')
        <form method="POST" action="{{ route('admin.kill-switches.resume') }}"
              data-confirm-action="Resume everything"
              data-confirm="Resume everything?"
              data-confirm-detail="Every switch currently on is turned off, and taxpayers can issue again at once.">
            @csrf
            @include('admin.partials.approval-reason', ['action' => 'kill_switches.resume'])
            <x-ui.button>Resume everything</x-ui.button>
        </form>
        @endadmincan
    @endif
</div>

@if ($status['is_fully_operational'])
    <div class="mb-6 rounded-lg border border-success-line bg-success-soft px-4 py-3 text-sm text-success">
        Nothing is stopped. Every step is running for every taxpayer.
    </div>
@else
    <div class="mb-6 rounded-lg border border-danger-line bg-danger-soft px-4 py-3 text-sm text-danger">
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

        <div class="rounded-card bg-surface px-6 py-5 border border-line shadow-card">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="min-w-0">
                    <div class="flex items-center gap-3">
                        <h3 class="text-lg font-semibold text-fg">{{ $name }}</h3>

                        <x-ui.badge :tone="$state['enabled'] ? 'danger' : 'success'"
                            :label="$state['enabled'] ? 'Stopped' : 'Running'" />
                    </div>

                    <p class="mt-1 text-sm text-muted">{{ $description }}</p>

                    @if ($state['enabled'] && $info)
                        <dl class="mt-3 grid gap-x-6 gap-y-1 text-sm sm:grid-cols-2">
                            <div class="flex gap-2">
                                <dt class="text-muted">Reason</dt>
                                <dd class="text-fg">{{ $info['reason'] ?? 'none given' }}</dd>
                            </div>
                            <div class="flex gap-2">
                                <dt class="text-muted">Stopped by</dt>
                                <dd class="text-fg">{{ $info['enabled_by'] ?? 'unknown' }}</dd>
                            </div>
                            <div class="flex gap-2">
                                <dt class="text-muted">Since</dt>
                                <dd class="text-fg">{{ $info['enabled_at'] ?? 'unknown' }}</dd>
                            </div>
                            <div class="flex gap-2">
                                <dt class="text-muted">Until</dt>
                                <dd class="text-fg">
                                    {{-- No expiry is the dangerous case, so it says so rather than
                                         showing an empty cell. --}}
                                    {{ $state['is_time_boxed'] ? $state['expires_at'] : 'turned off by hand' }}
                                </dd>
                            </div>
                        </dl>
                    @endif
                </div>

                {{-- shrink-0 unconditionally kept this at its 398px intrinsic
                     width inside a flex row, so the whole page scrolled
                     sideways on a phone. It only needs to hold its width once
                     there is room beside the switch's name. --}}
                <div class="w-full sm:w-auto sm:shrink-0">
                    @if ($state['enabled'])
                        @admincan('platform.configure')
                        <form method="POST" action="{{ route('admin.kill-switches.disable') }}"
                              data-confirm-action="Resume"
                              data-confirm="Resume {{ strtolower($name) }}?"
                              data-confirm-detail="Taxpayers can do this again immediately. You can stop it again.">
                            @csrf
                            <input type="hidden" name="switch" value="{{ $switch }}">
                            @include('admin.partials.approval-reason', ['action' => 'kill_switches.disable', 'compact' => true])
                            <x-ui.button variant="secondary">Resume</x-ui.button>
                        </form>
                        @endadmincan
                    @else
                        @admincan('platform.configure')
                        <form method="POST" action="{{ route('admin.kill-switches.enable') }}"
                              class="flex flex-wrap items-end justify-end gap-2"
                              data-confirm-tone="danger" data-confirm-action="Stop"
                              data-confirm="Stop {{ strtolower($name) }} for every taxpayer?"
                              data-confirm-detail="It stops on the next request, until you resume it.">
                            @csrf
                            <input type="hidden" name="switch" value="{{ $switch }}">

                            <div>
                                <label for="reason-{{ $switch }}" class="mb-1 block text-xs font-medium text-muted">
                                    Reason
                                </label>
                                <x-ui.input id="reason-{{ $switch }}" name="reason" type="text" required
                                       minlength="5" maxlength="500" placeholder="Why this is stopping" class="sm:w-56" />
                            </div>

                            <div>
                                <label for="minutes-{{ $switch }}" class="mb-1 block text-xs font-medium text-muted">
                                    Minutes
                                </label>
                                <x-ui.input id="minutes-{{ $switch }}" name="minutes" type="number" min="1"
                                       max="{{ $maxMinutes }}" placeholder="optional" class="sm:w-28" />
                            </div>

                            <x-ui.button variant="danger">Stop</x-ui.button>
                        </form>
                        @endadmincan

                        <p class="mt-1 text-right text-xs text-subtle">
                            Leave minutes empty and it stays on until someone turns it off.
                        </p>
                    @endif
                </div>
            </div>
        </div>
    @endforeach
</div>
@endsection
