@props(['ticket', 'attachmentRoute'])

{{--
    A ticket's conversation, for the portal and the console alike.

    The portal is handed only the messages its people may see; notes between
    admins are left out before they reach here, not hidden by it.
--}}
<ol class="space-y-4">
    @foreach ($ticket->messages as $message)
        <li @class([
            'rounded-lg border p-4',
            'border-warning-line bg-warning-soft' => $message->internal,
            'border-accent-line bg-accent-soft' => $message->from_staff && ! $message->internal,
            'border-line bg-surface' => ! $message->from_staff,
        ])>
            <div class="mb-2 flex flex-wrap items-center gap-2 text-xs text-muted">
                <span class="font-medium text-fg">
                    {{ $message->from_staff ? __('Support') : ($message->author?->name ?? __('Deleted user')) }}
                </span>
                @if ($message->from_staff && $message->author)
                    <span>({{ $message->author->name }})</span>
                @endif
                @if ($message->internal)
                    <x-ui.badge tone="warning" label="Internal note" />
                @endif
                <x-ui.date :value="$message->created_at" time />
            </div>
            <div class="whitespace-pre-line break-words text-sm text-fg">{{ $message->body }}</div>
            @if ($message->attachments->isNotEmpty())
                <ul class="mt-3 flex flex-wrap gap-2">
                    @foreach ($message->attachments as $attachment)
                        <li>
                            <a href="{{ route($attachmentRoute, [$ticket->id, $attachment->id]) }}"
                               class="inline-flex items-center gap-1 rounded border border-line-strong px-2 py-1 text-xs text-accent hover:bg-sunken">
                                {{ $attachment->name }}
                                <span class="text-subtle">{{ __(':size KB', ['size' => number_format($attachment->size / 1024, 0)]) }}</span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            @endif
        </li>
    @endforeach
</ol>
