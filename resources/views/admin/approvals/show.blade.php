@extends('layouts.admin')

@section('title', $label)

@php
    $before = (array) ($approval->changes['before'] ?? []);
    $after = (array) ($approval->changes['after'] ?? []);
    $fields = array_values(array_unique([...array_keys($before), ...array_keys($after)]));
    $shown = fn (mixed $value): string => match (true) {
        $value === null => '-',
        is_bool($value) => $value ? __('Yes') : __('No'),
        is_array($value) => implode(', ', array_map(fn ($item) => is_scalar($item) ? (string) $item : json_encode($item), $value)),
        default => (string) $value,
    };
@endphp

@section('content')
<x-ui.page-header :title="$label" :description="$approval->summary">
    <x-slot:breadcrumb>
        <x-ui.breadcrumb :items="[['label' => __('Approvals'), 'href' => route('admin.approvals.index')], ['label' => $label]]" />
    </x-slot:breadcrumb>
</x-ui.page-header>

@if (session(\App\Domains\Platform\Http\Controllers\AdminApprovalsController::LINK))
    <x-ui.card class="mb-6 max-w-3xl">
        <x-ui.field :label="__('One-time enrolment link')" :hint="__('Shown only now. Send it by a channel you trust; it works once.')">
            <x-ui.input type="text" readonly dir="ltr" value="{{ session(\App\Domains\Platform\Http\Controllers\AdminApprovalsController::LINK) }}" />
        </x-ui.field>
    </x-ui.card>
@endif

<div class="grid max-w-5xl grid-cols-1 gap-6 lg:grid-cols-3">
    <div class="space-y-6 lg:col-span-2">
        <x-ui.card :title="__('What changes')" flush>
            <x-ui.table :primary="[0, 2]">
                <x-slot:head>
                    <x-ui.th>{{ __('Field') }}</x-ui.th>
                    <x-ui.th>{{ __('Now') }}</x-ui.th>
                    <x-ui.th>{{ __('After') }}</x-ui.th>
                </x-slot:head>
                @forelse ($fields as $field)
                    <tr>
                        <td class="px-4 py-3 text-sm font-medium text-fg" dir="ltr">{{ $field }}</td>
                        <td class="px-4 py-3 text-sm text-muted">{{ $shown($before[$field] ?? null) }}</td>
                        <td @class(['px-4 py-3 text-sm', 'font-semibold text-fg' => ($before[$field] ?? null) !== ($after[$field] ?? null), 'text-muted' => ($before[$field] ?? null) === ($after[$field] ?? null)])>{{ $shown($after[$field] ?? null) }}</td>
                    </tr>
                @empty
                    <x-ui.empty :columns="3">{{ __('Nothing to compare: this action makes something new.') }}</x-ui.empty>
                @endforelse
            </x-ui.table>
        </x-ui.card>

        <x-ui.card :title="__('Why')">
            <p class="whitespace-pre-line text-sm text-fg">{{ $approval->reason }}</p>
        </x-ui.card>

        @if ($mayDecide)
            <x-ui.card :title="__('Your decision')" :description="__('Approving does it exactly as asked, once enough admins have approved. Rejecting ends the request; the admin who asked is told why.')">
                <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                    <form method="POST" action="{{ route('admin.approvals.approve', $approval->id) }}" class="space-y-3"
                          data-confirm-action="{{ __('Approve') }}" data-confirm="{{ __('Approve this change? It may happen at once.') }}">
                        @csrf
                        <x-ui.field :label="__('Note')" optional error="note">
                            <x-ui.textarea name="note" rows="2" maxlength="1000">{{ old('note') }}</x-ui.textarea>
                        </x-ui.field>
                        <x-ui.button variant="primary">{{ __('Approve') }}</x-ui.button>
                    </form>
                    <form method="POST" action="{{ route('admin.approvals.reject', $approval->id) }}" class="space-y-3">
                        @csrf
                        <x-ui.field :label="__('Reason for rejecting')" error="reason">
                            <x-ui.textarea name="reason" rows="2" maxlength="1000" required>{{ old('reason') }}</x-ui.textarea>
                        </x-ui.field>
                        <x-ui.button variant="danger">{{ __('Reject') }}</x-ui.button>
                    </form>
                </div>
            </x-ui.card>
        @endif
    </div>

    <div class="space-y-6">
        <x-ui.card :title="__('Status')">
            <dl class="space-y-3 text-sm">
                <div>
                    <dt class="text-subtle">{{ __('Status') }}</dt>
                    <dd class="mt-0.5"><x-ui.badge :tone="$approval->tone()" :label="$approval->statusLabel()" /></dd>
                </div>
                <div>
                    <dt class="text-subtle">{{ __('Asked by') }}</dt>
                    <dd class="mt-0.5 text-fg">{{ $approval->requester?->email }} · {{ $approval->created_at->diffForHumans() }}</dd>
                </div>
                <div>
                    <dt class="text-subtle">{{ __('Approvals') }}</dt>
                    <dd class="mt-0.5 text-fg">{{ __(':given of :needed', ['given' => $approval->decisions->count(), 'needed' => $approval->approvals_required]) }}</dd>
                </div>
                <div>
                    <dt class="text-subtle">{{ __('Who may approve') }}</dt>
                    <dd class="mt-0.5 text-fg">{{ implode(', ', array_map('ucfirst', (array) $approval->approver_roles)) }}</dd>
                </div>
                @if ($approval->status === 'pending')
                    <div>
                        <dt class="text-subtle">{{ __('Expires') }}</dt>
                        <dd class="mt-0.5 text-fg">{{ $approval->expires_at->diffForHumans() }}</dd>
                    </div>
                @else
                    <div>
                        <dt class="text-subtle">{{ __('Decided') }}</dt>
                        <dd class="mt-0.5 text-fg">{{ $approval->decider?->email }} · {{ $approval->decided_at?->diffForHumans() }}</dd>
                    </div>
                @endif
                @if ($approval->decision_note)
                    <div>
                        <dt class="text-subtle">{{ __('Reason for rejecting') }}</dt>
                        <dd class="mt-0.5 whitespace-pre-line text-fg">{{ $approval->decision_note }}</dd>
                    </div>
                @endif
                @if ($approval->outcome)
                    <div>
                        <dt class="text-subtle">{{ __('What happened') }}</dt>
                        <dd class="mt-0.5 text-fg">{{ $approval->outcome }}</dd>
                    </div>
                @endif
            </dl>
        </x-ui.card>

        @if ($approval->decisions->isNotEmpty())
            <x-ui.card :title="__('Approved by')">
                <ul class="space-y-2 text-sm">
                    @foreach ($approval->decisions as $decision)
                        <li>
                            <span class="text-fg">{{ $decision->user?->email }}</span>
                            <span class="text-subtle">· {{ $decision->created_at->diffForHumans() }}</span>
                            @if ($decision->note)
                                <p class="mt-0.5 text-muted">{{ $decision->note }}</p>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </x-ui.card>
        @endif

        @if ($approval->status === 'pending' && $approval->requested_by === auth()->id())
            <form method="POST" action="{{ route('admin.approvals.withdraw', $approval->id) }}"
                  data-confirm-tone="danger" data-confirm-action="{{ __('Withdraw') }}" data-confirm="{{ __('Withdraw this request? Nothing will change.') }}">
                @csrf
                <x-ui.button variant="secondary">{{ __('Withdraw the request') }}</x-ui.button>
            </form>
        @endif
    </div>
</div>
@endsection
