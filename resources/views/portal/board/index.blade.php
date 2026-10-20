@extends('layouts.portal')

@section('title', __('Feature board'))

@section('content')
<x-ui.page-header :title="__('Feature board')" :description="__('What customers have asked for, in our words and with no company named. Back what your company needs too, and follow it to hear as it moves on.')">
    <x-slot:breadcrumb>
        <x-ui.breadcrumb :items="[['label' => __('Requests'), 'href' => route('portal.support.index')], ['label' => __('Feature board')]]" />
    </x-slot:breadcrumb>
    <x-slot:actions>
        <x-ui.button variant="secondary" :href="route('portal.support.create', ['kind' => 'feature'])">{{ __('Ask for a feature') }}</x-ui.button>
    </x-slot:actions>
</x-ui.page-header>

<form method="GET" action="{{ route('portal.board.index') }}" class="mb-6 flex flex-wrap items-end gap-4 rounded-card border border-line bg-surface p-4 shadow-card">
    <div class="min-w-48 flex-1">
        <x-ui.field :label="__('Category')">
            <x-ui.select id="category" name="category">
                <option value="">{{ __('Any category') }}</option>
                @foreach ($categories as $option)
                    <option value="{{ $option->key }}" @selected($category === $option->key)>{{ $option->name() }}</option>
                @endforeach
            </x-ui.select>
        </x-ui.field>
    </div>
    <div class="min-w-48">
        <x-ui.field :label="__('Order')">
            <x-ui.select id="sort" name="sort">
                <option value="demand" @selected($sort === 'demand')>{{ __('Most wanted first') }}</option>
                <option value="newest" @selected($sort === 'newest')>{{ __('Newest first') }}</option>
            </x-ui.select>
        </x-ui.field>
    </div>
    <x-ui.button variant="secondary">{{ __('Show') }}</x-ui.button>
</form>

<div class="overflow-hidden rounded-card border border-line bg-surface shadow-card">
    <x-ui.table :primary="[0, 2, 3]">
        <x-slot:head>
            <x-ui.th>{{ __('Feature') }}</x-ui.th>
            <x-ui.th>{{ __('Category') }}</x-ui.th>
            <x-ui.th>{{ __('Stage') }}</x-ui.th>
            <x-ui.th>{{ __('Companies asking') }}</x-ui.th>
            <x-ui.th>{{ __('Your company') }}</x-ui.th>
        </x-slot:head>
        @forelse ($standings as $standing)
        <tr class="hover:bg-sunken">
            <td class="px-6 py-4 text-sm">
                <a href="{{ route('portal.board.show', $standing->item->id) }}" class="font-medium text-accent hover:underline">{{ $standing->item->localTitle() }}</a>
                <span class="block font-mono text-xs text-subtle" dir="ltr">{{ $standing->item->reference() }}</span>
            </td>
            <td class="px-6 py-4 text-sm text-muted">{{ $standing->categoryName }}</td>
            <td class="px-6 py-4"><x-ui.badge :tone="$standing->item->stage->tone()" :label="$standing->item->stage->label()" /></td>
            <td class="px-6 py-4 text-sm text-fg tabular-nums">{{ $standing->demand }}</td>
            <td class="px-6 py-4 text-sm text-muted">
                @if ($standing->askedByUs)
                    {{ __('Asked for it') }}
                @elseif ($standing->backedByUs)
                    {{ __('Backs it') }}
                @else
                    -
                @endif
            </td>
        </tr>
        @empty
        <x-ui.empty :columns="5">{{ __('Nothing on the board yet') }}</x-ui.empty>
        @endforelse
    </x-ui.table>
</div>
@endsection
