@props(['organizationId'])

{{--
    One branch, or all of them, for the dashboard, the lists, the reports
    and the day's close - and the branch a new invoice is issued from.

    The choice is the person's own, kept from one visit to the next
    (WorkingBranches), and offers only the branches they work at: a cashier
    assigned to two branches chooses between those two, and "All branches"
    is both of them. Hidden for an organization with no branches.
--}}
@php
    $userId = (string) auth()->id();
    $options = app(\App\Domains\Organization\Services\WorkingBranches::class)->options($userId, $organizationId);
    $current = app(\App\Domains\Organization\Services\BranchScope::class)->selected();
@endphp

@if ($options !== [])
    <form method="POST" action="{{ route('portal.branch.choose') }}" {{ $attributes->merge(['class' => 'mt-3 flex items-end gap-2']) }}>
        @csrf
        <x-ui.field :label="__('Branch')" class="min-w-0 flex-1">
            <x-ui.select name="branch">
                <option value="">{{ __('All branches') }}</option>
                @foreach ($options as $id => $name)
                    <option value="{{ $id }}" @selected($current === $id)>{{ $name }}</option>
                @endforeach
            </x-ui.select>
        </x-ui.field>
        <x-ui.button variant="secondary" size="sm">{{ __('Show') }}</x-ui.button>
    </form>
@endif
