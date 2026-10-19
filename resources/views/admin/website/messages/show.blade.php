@extends('layouts.admin')

@section('title', $message->name)

@section('content')
{{--
    One message from the contact form. Replying is by email, from the
    admin's own mail - the link opens it addressed and titled in the
    sender's language - and then marked replied here. The network address
    and browser are for spam review only, and are cleared after the keeping
    period (PruneContactDetails).
--}}
@php
    $subject = __('Re: your message to :name', ['name' => \App\Support\Brand::name($message->locale)], $message->locale);
    $mailto = 'mailto:'.$message->email.'?subject='.rawurlencode($subject);
@endphp

<x-ui.page-header :title="$message->name">
    <x-slot:breadcrumb>
        <x-ui.breadcrumb :items="[
            ['label' => __('Messages'), 'href' => route('admin.website.messages.index')],
            ['label' => $message->name],
        ]" />
    </x-slot:breadcrumb>
    <x-slot:meta>
        <x-ui.badge :tone="$message->status->tone()" :label="$message->status->label()" />
    </x-slot:meta>
    <x-slot:description>{{ $message->topic->label() }} &middot; <x-ui.date :value="$message->created_at" time /></x-slot:description>
</x-ui.page-header>

<div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
    <div class="space-y-6 lg:col-span-2">
        <x-ui.card :title="__('Message')">
            <p class="whitespace-pre-line text-sm text-fg" dir="auto">{{ $message->message }}</p>
        </x-ui.card>

        <div class="flex flex-wrap items-center gap-3">
            <x-ui.button :href="$mailto">{{ __('Reply by email') }}</x-ui.button>
            @if ($message->status !== \App\Domains\Platform\Website\ContactStatus::Replied)
                <form method="POST" action="{{ route('admin.website.messages.replied', $message->id) }}">
                    @csrf
                    <x-ui.button variant="secondary">{{ __('Mark replied') }}</x-ui.button>
                </form>
            @endif
            @if ($message->status === \App\Domains\Platform\Website\ContactStatus::Archived)
                <form method="POST" action="{{ route('admin.website.messages.restore', $message->id) }}">
                    @csrf
                    <x-ui.button variant="ghost">{{ __('Restore') }}</x-ui.button>
                </form>
            @else
                <form method="POST" action="{{ route('admin.website.messages.archive', $message->id) }}">
                    @csrf
                    <x-ui.button variant="ghost">{{ __('Archive') }}</x-ui.button>
                </form>
            @endif
        </div>
    </div>

    <x-ui.card :title="__('Details')">
        <dl class="space-y-3 text-sm">
            <div>
                <dt class="text-muted">{{ __('Email') }}</dt>
                <dd><a href="{{ $mailto }}" class="text-accent hover:underline" dir="ltr">{{ $message->email }}</a></dd>
            </div>
            <div>
                <dt class="text-muted">{{ __('Company') }}</dt>
                <dd class="text-fg">{{ $message->company ?? '-' }}</dd>
            </div>
            <div>
                <dt class="text-muted">{{ __('Country') }}</dt>
                <dd class="text-fg">{{ $message->country ?? '-' }}</dd>
            </div>
            <div>
                <dt class="text-muted">{{ __('Page language') }}</dt>
                <dd class="text-fg">{{ $message->locale === 'ar' ? 'العربية' : 'English' }}</dd>
            </div>
            <div>
                <dt class="text-muted">{{ __('Read') }}</dt>
                <dd class="text-fg"><x-ui.date :value="$message->read_at" time /> @if ($message->reader) &middot; {{ $message->reader->name }} @endif</dd>
            </div>
            <div>
                <dt class="text-muted">{{ __('Replied') }}</dt>
                <dd class="text-fg"><x-ui.date :value="$message->replied_at" time /> @if ($message->replier) &middot; {{ $message->replier->name }} @endif</dd>
            </div>
            <div>
                <dt class="text-muted">{{ __('Network address and browser') }}</dt>
                @if ($message->ip_address || $message->user_agent)
                    <dd class="break-all font-mono text-xs text-fg" dir="ltr">{{ $message->ip_address }}<br>{{ $message->user_agent }}</dd>
                    <dd class="mt-1 text-xs text-subtle">{{ __('For spam review only. Cleared :days days after the message arrived.', ['days' => config('website.contact.keep_details_days')]) }}</dd>
                @else
                    <dd class="text-xs text-subtle">{{ __('Cleared after :days days.', ['days' => config('website.contact.keep_details_days')]) }}</dd>
                @endif
            </div>
        </dl>
    </x-ui.card>
</div>
@endsection
