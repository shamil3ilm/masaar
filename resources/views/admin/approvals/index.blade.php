@extends('layouts.admin')

@section('title', __('Approvals'))

@section('content')
<x-ui.page-header :title="__('Approvals')"
                  :description="__('Changes another admin asked for that wait for a second admin before they happen. Nobody approves their own request.')">
    <x-slot:actions>
        <x-ui.button variant="secondary" :href="route('admin.approval-policies.index')">{{ __('Approval policies') }}</x-ui.button>
    </x-slot:actions>
</x-ui.page-header>

<x-ui.tabs id="approvals" :label="__('Approvals')" :tabs="[
    'waiting' => __('Waiting for me').' ('.$waiting->count().')',
    'mine' => __('Asked for by me'),
    'history' => __('History'),
]">
    <x-ui.tab-panel id="approvals" name="waiting" selected>
        @include('admin.approvals.partials.list', ['requests' => $waiting, 'empty' => __('Nothing is waiting for your approval.')])
    </x-ui.tab-panel>
    <x-ui.tab-panel id="approvals" name="mine">
        @include('admin.approvals.partials.list', ['requests' => $mine, 'empty' => __('You have not asked for anything that needs approval.')])
    </x-ui.tab-panel>
    <x-ui.tab-panel id="approvals" name="history">
        @include('admin.approvals.partials.list', ['requests' => $history, 'empty' => __('No request has been decided yet.')])
    </x-ui.tab-panel>
</x-ui.tabs>

@admincan('approvals.decide')
    <x-ui.card class="mt-8 max-w-3xl" flush>
        <x-customization :resolved="$notifications" :action="route('admin.approvals.notifications')" />
    </x-ui.card>
@endadmincan
@endsection
