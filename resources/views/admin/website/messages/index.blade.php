@extends('layouts.admin')

@section('title', __('Messages'))

@section('content')
{{--
    Website › Messages: what people sent from the home page's contact form,
    newest first. A message nobody has opened is in bold.
--}}
<x-ui.page-header :eyebrow="__('Website')" :title="__('Messages')"
    :description="__('What people sent from the home page\'s contact form. Answer by email, then mark it replied.')" />

<x-ui.list-filters :list="$list" />

<div class="overflow-hidden rounded-card border border-line bg-surface shadow-card">
    <x-ui.table :primary="[1, 2, 3]">
        <x-slot:head>
            <x-ui.th :list="$list" sort="newest">{{ __('Received') }}</x-ui.th>
            <x-ui.th :list="$list" sort="name">{{ __('From') }}</x-ui.th>
            <x-ui.th>{{ __('Topic') }}</x-ui.th>
            <x-ui.th>{{ __('Status') }}</x-ui.th>
            <x-ui.th>{{ __('Company') }}</x-ui.th>
        </x-slot:head>

        @forelse ($messages as $message)
            <tr @class(['hover:bg-sunken', 'font-semibold' => $message->read_at === null])>
                <td class="px-6 py-4 text-sm text-muted whitespace-nowrap">{{ $message->created_at->format('Y-m-d H:i') }}</td>
                <td class="px-6 py-4 text-sm">
                    <a href="{{ route('admin.website.messages.show', $message->id) }}" class="text-accent hover:text-accent-strong hover:underline">{{ $message->name }}</a>
                    <div class="text-xs font-normal text-muted" dir="ltr">{{ $message->email }}</div>
                </td>
                <td class="px-6 py-4 text-sm text-fg">{{ $message->topic->label() }}</td>
                <td class="px-6 py-4"><x-ui.badge :tone="$message->status->tone()" :label="$message->status->label()" /></td>
                <td class="px-6 py-4 text-sm text-muted">{{ $message->company ?? '-' }}</td>
            </tr>
        @empty
            <x-ui.empty :columns="5">{{ __('No messages match these filters') }}</x-ui.empty>
        @endforelse
    </x-ui.table>
</div>

{{ $messages->links() }}
@endsection
