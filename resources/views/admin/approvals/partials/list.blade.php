{{--
    One list of approval requests: what was asked, by whom, where it stands.

    @param \Illuminate\Support\Collection<int, \App\Domains\Platform\Models\ApprovalRequest> $requests
    @param \App\Domains\Platform\Approvals\ApprovalCatalogue $catalogue
    @param string $empty  what to say when there are none
--}}
<x-ui.card flush>
    <x-ui.table :primary="[0, 2]">
        <x-slot:head>
            <x-ui.th>{{ __('Request') }}</x-ui.th>
            <x-ui.th>{{ __('Asked by') }}</x-ui.th>
            <x-ui.th>{{ __('Status') }}</x-ui.th>
            <x-ui.th>{{ __('Expires') }}</x-ui.th>
        </x-slot:head>
        @forelse ($requests as $approval)
            <tr>
                <td class="px-4 py-3">
                    <a href="{{ route('admin.approvals.show', $approval->id) }}" class="font-medium text-accent hover:underline underline-offset-4">{{ $catalogue->label($approval->action) }}</a>
                    <div class="mt-0.5 text-sm text-muted">{{ $approval->summary }}</div>
                </td>
                <td class="px-4 py-3 text-sm text-muted">{{ $approval->requester?->email }}</td>
                <td class="px-4 py-3"><x-ui.badge :tone="$approval->tone()" :label="$approval->statusLabel()" /></td>
                <td class="px-4 py-3 text-sm text-muted">{{ $approval->status === 'pending' ? $approval->expires_at->diffForHumans() : $approval->decided_at?->diffForHumans() }}</td>
            </tr>
        @empty
            <x-ui.empty :columns="4">{{ $empty }}</x-ui.empty>
        @endforelse
    </x-ui.table>
</x-ui.card>
