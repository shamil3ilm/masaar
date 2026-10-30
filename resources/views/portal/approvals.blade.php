@extends('layouts.portal')

@section('title', __('Approvals'))

{{--
    Credit and debit notes waiting for a second person (NoteIssuing,
    OrganizationApprovals): those this person may decide, those they asked
    for, and what became of the rest.

    @param \Illuminate\Support\Collection $waiting   requests this person may decide
    @param \Illuminate\Support\Collection $mine      requests this person made
    @param \Illuminate\Support\Collection $history   requests decided
    @param bool $hasOtherApprover      whether anyone else could approve this person's notes
    @param bool $policyOn              whether notes wait for approval here
--}}

@section('content')
<x-ui.page-header :title="__('Approvals')"
                  :description="__('Credit and debit notes wait here for another owner or admin to approve them, when your organization asks for a second person.')" class="mb-6" />

@if (! $policyOn)
    <x-ui.alert tone="info" class="mb-6">
        <p>{{ __('Notes are issued without a second person. An administrator can change this under Settings, Approvals.') }}</p>
    </x-ui.alert>
@elseif (! $hasOtherApprover)
    <x-ui.alert tone="warning" class="mb-6" data-no-other-approver>
        <p>{{ __('Nobody else in your organization can approve notes, so your notes wait until another owner or admin can. Add one under Team, or turn the approval off under Settings, Approvals.') }}</p>
    </x-ui.alert>
@endif

<x-ui.card :title="__('Waiting for you')" flush class="mb-6 overflow-hidden">
    <x-ui.table :primary="[0, 3]">
        <x-slot:head>
            <x-ui.th>{{ __('Note') }}</x-ui.th>
            <x-ui.th>{{ __('Asked by') }}</x-ui.th>
            <x-ui.th>{{ __('Reason') }}</x-ui.th>
            <x-ui.th>{{ __('Decision') }}</x-ui.th>
        </x-slot:head>
        @forelse ($waiting as $approval)
            <tr>
                <td class="px-6 py-3 text-sm">
                    <a class="font-medium text-accent hover:text-accent-strong" href="{{ route('portal.invoices.show', $approval->target_id) }}">{{ $approval->summary }}</a>
                </td>
                <td class="px-6 py-3 text-sm text-muted">{{ $approval->requester?->name ?? $approval->requester?->email ?? __('An integration (API key)') }}</td>
                <td class="px-6 py-3 text-sm text-muted">{{ $approval->reason }}</td>
                <td class="px-6 py-3 text-sm">
                    <div class="flex flex-wrap items-end gap-2">
                        <form method="POST" action="{{ route('portal.approvals.approve', $approval->id) }}"
                              data-confirm="{{ __('Approve and issue this note?') }}"
                              data-confirm-detail="{{ __('It is issued now, as it was asked for, with every check run again. An issued note cannot be taken back.') }}"
                              data-confirm-action="{{ __('Approve') }}">
                            @csrf
                            <x-ui.button type="submit" size="sm">{{ __('Approve') }}</x-ui.button>
                        </form>
                        <form method="POST" action="{{ route('portal.approvals.reject', $approval->id) }}" class="flex flex-wrap items-end gap-2"
                              data-confirm="{{ __('Reject this note?') }}"
                              data-confirm-detail="{{ __('It stays a draft, and the person who asked sees your reason.') }}"
                              data-confirm-action="{{ __('Reject') }}" data-confirm-tone="danger">
                            @csrf
                            <x-ui.field :label="__('Reason')">
                                <x-ui.input name="reason" required maxlength="2000" />
                            </x-ui.field>
                            <x-ui.button type="submit" size="sm" variant="secondary">{{ __('Reject') }}</x-ui.button>
                        </form>
                    </div>
                </td>
            </tr>
        @empty
            <x-ui.empty :columns="4">{{ __('Nothing is waiting for you.') }}</x-ui.empty>
        @endforelse
    </x-ui.table>
</x-ui.card>

<x-ui.card :title="__('Asked for by you')" flush class="mb-6 overflow-hidden">
    <x-ui.table :primary="[0, 1]">
        <x-slot:head>
            <x-ui.th>{{ __('Note') }}</x-ui.th>
            <x-ui.th>{{ __('Status') }}</x-ui.th>
            <x-ui.th>{{ __('Outcome') }}</x-ui.th>
            <x-ui.th>{{ __('Action') }}</x-ui.th>
        </x-slot:head>
        @forelse ($mine as $approval)
            <tr>
                <td class="px-6 py-3 text-sm">
                    <a class="font-medium text-accent hover:text-accent-strong" href="{{ route('portal.invoices.show', $approval->target_id) }}">{{ $approval->summary }}</a>
                </td>
                <td class="px-6 py-3 text-sm"><x-ui.badge :tone="$approval->tone()" :label="$approval->statusLabel()" /></td>
                <td class="px-6 py-3 text-sm text-muted">{{ $approval->decision_note ?? $approval->outcome ?? '-' }}</td>
                <td class="px-6 py-3 text-sm">
                    @if ($approval->isPending())
                        <form method="POST" action="{{ route('portal.approvals.withdraw', $approval->id) }}"
                              data-confirm="{{ __('Withdraw this request?') }}"
                              data-confirm-detail="{{ __('The note stays a draft. You can send it for approval again.') }}"
                              data-confirm-action="{{ __('Withdraw') }}">
                            @csrf
                            <x-ui.button type="submit" size="sm" variant="secondary">{{ __('Withdraw') }}</x-ui.button>
                        </form>
                    @else
                        <span class="text-subtle">-</span>
                    @endif
                </td>
            </tr>
        @empty
            <x-ui.empty :columns="4">{{ __('You have not sent a note for approval.') }}</x-ui.empty>
        @endforelse
    </x-ui.table>
</x-ui.card>

<x-ui.card :title="__('Decided')" flush class="overflow-hidden">
    <x-ui.table :primary="[0, 1]">
        <x-slot:head>
            <x-ui.th>{{ __('Note') }}</x-ui.th>
            <x-ui.th>{{ __('Status') }}</x-ui.th>
            <x-ui.th>{{ __('Asked by') }}</x-ui.th>
            <x-ui.th>{{ __('Decided by') }}</x-ui.th>
        </x-slot:head>
        @forelse ($history as $approval)
            <tr>
                <td class="px-6 py-3 text-sm">{{ $approval->summary }}</td>
                <td class="px-6 py-3 text-sm"><x-ui.badge :tone="$approval->tone()" :label="$approval->statusLabel()" /></td>
                <td class="px-6 py-3 text-sm text-muted">{{ $approval->requester?->name ?? $approval->requester?->email ?? __('An integration (API key)') }}</td>
                <td class="px-6 py-3 text-sm text-muted">{{ $approval->decidedBy()?->name ?? $approval->decidedBy()?->email ?? '-' }}</td>
            </tr>
        @empty
            <x-ui.empty :columns="4">{{ __('Nothing has been decided yet.') }}</x-ui.empty>
        @endforelse
    </x-ui.table>
</x-ui.card>
@endsection
