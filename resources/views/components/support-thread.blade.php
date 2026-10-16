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
            'border-amber-200 dark:border-amber-800 bg-amber-50 dark:bg-amber-950/40' => $message->internal,
            'border-teal-200 dark:border-teal-800 bg-teal-50 dark:bg-teal-950/40' => $message->from_staff && ! $message->internal,
            'border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900' => ! $message->from_staff,
        ])>
            <div class="mb-2 flex flex-wrap items-center gap-2 text-xs text-gray-500 dark:text-gray-400">
                <span class="font-medium text-gray-800 dark:text-gray-100">
                    {{ $message->from_staff ? __('Support') : ($message->author?->name ?? __('Deleted user')) }}
                </span>
                @if ($message->from_staff && $message->author)
                    <span>({{ $message->author->name }})</span>
                @endif
                @if ($message->internal)
                    <x-ui.badge tone="warning" label="Internal note" />
                @endif
                <span>{{ $message->created_at->translatedFormat('M d, Y H:i') }}</span>
            </div>
            <div class="whitespace-pre-line break-words text-sm text-gray-800 dark:text-gray-100">{{ $message->body }}</div>
            @if ($message->attachments->isNotEmpty())
                <ul class="mt-3 flex flex-wrap gap-2">
                    @foreach ($message->attachments as $attachment)
                        <li>
                            <a href="{{ route($attachmentRoute, [$ticket->id, $attachment->id]) }}"
                               class="inline-flex items-center gap-1 rounded border border-gray-300 dark:border-gray-600 px-2 py-1 text-xs text-teal-700 dark:text-teal-300 hover:bg-gray-50 dark:hover:bg-gray-950">
                                {{ $attachment->name }}
                                <span class="text-gray-400 dark:text-gray-500">{{ __(':size KB', ['size' => number_format($attachment->size / 1024, 0)]) }}</span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            @endif
        </li>
    @endforeach
</ol>
