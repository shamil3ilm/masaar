{{--
    A branch's tills as ZATCA EGS units, on the shared branch page (Devices::branchStatus).

    $branch  the branch's id
    $tills   each till: id, name, number, status, status_label, onboarding,
             ready (ZATCA accepts invoices from it now), serial (what its
             certificate was issued against), compliance and production
             (whether it holds those CSIDs), expires and days (its certificate)
--}}
<x-ui.section :title="__('Tills')" :description="__('Each till is its own ZATCA device: its own certificate, counter and numbering, connected with its own one-time code.')" flush>
    <x-ui.table :primary="[0, 1, 2]">
        <x-slot:head>
            <x-ui.th>{{ __('Till') }}</x-ui.th>
            <x-ui.th>{{ __('Status') }}</x-ui.th>
            <x-ui.th>{{ __('ZATCA') }}</x-ui.th>
            <x-ui.th>{{ __('Certificate') }}</x-ui.th>
            <x-ui.th>{{ __('Device serial') }}</x-ui.th>
        </x-slot:head>
        @foreach ($tills as $till)
            <tr>
                <td class="px-4 py-3 text-sm font-medium text-fg">
                    {{ $till['name'] }}
                    <span class="block text-xs text-muted">{{ __('Till :number', ['number' => $till['number']]) }}</span>
                </td>
                <td class="px-4 py-3 text-sm">
                    <x-ui.badge :state="$till['status']" :tone="$till['status'] === 'active' ? null : 'neutral'" :label="$till['status_label']" />
                </td>
                <td class="px-4 py-3 text-sm">
                    <x-ui.badge :tone="$till['ready'] ? 'success' : 'warning'" :label="$till['ready'] ? __('Ready to issue') : __('Not connected to ZATCA')" />
                </td>
                <td class="px-4 py-3 text-sm text-fg">
                    @if ($till['expires'])
                        <span dir="ltr">{{ $till['expires']->toDateString() }}</span>
                        @if ($till['days'] !== null)
                            <span class="block text-xs text-muted">{{ $till['days'] >= 0 ? __(':days days left', ['days' => $till['days']]) : __('Expired') }}</span>
                        @endif
                    @else
                        <span class="text-muted">{{ $till['production'] ? __('No end date recorded') : __('Signs with head office\'s certificate until it has its own.') }}</span>
                    @endif
                </td>
                <td class="px-4 py-3 font-mono text-xs text-muted break-all" dir="ltr">{{ $till['serial'] ?: '-' }}</td>
            </tr>
        @endforeach
    </x-ui.table>

    <div class="flex flex-wrap items-end gap-3 border-t border-line p-5">
        <form method="POST" action="{{ route('portal.branches.tills.store', $branch) }}" class="flex flex-wrap items-end gap-3">
            @csrf
            <x-ui.field :label="__('New till\'s name')" error="name" optional>
                <x-ui.input type="text" name="name" maxlength="100" />
            </x-ui.field>
            <x-ui.button variant="secondary">{{ __('Add a till') }}</x-ui.button>
        </form>
        <x-ui.button variant="ghost" :href="route('portal.zatca.certificates')">{{ __('Certificates') }}</x-ui.button>
    </div>
</x-ui.section>
