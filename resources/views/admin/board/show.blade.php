@extends('layouts.admin')

@section('title', $item->reference())

@section('content')
<x-ui.page-header :title="$item->title">
    <x-slot:breadcrumb>
        <x-ui.breadcrumb :items="[['label' => 'Feature board', 'href' => route('admin.board.index')], ['label' => $item->reference()]]" />
    </x-slot:breadcrumb>
    <x-slot:meta>
        <x-ui.badge :tone="$item->stage->tone()" :label="$item->stage->label()" />
        <x-ui.badge :tone="$item->published() ? 'success' : 'neutral'" :label="$item->published() ? 'Published' : ($item->merged_into_id ? 'Merged' : 'Draft')" />
    </x-slot:meta>
    <x-slot:description>
        {{ $standing?->categoryName }} &middot; {{ $standing?->demand ?? 0 }} companies want it &middot; {{ $item->backers_count }} back it &middot; {{ $item->followers_count }} follow it
        @if ($item->mergedInto)
            &middot; merged into <a href="{{ route('admin.board.show', $item->merged_into_id) }}" class="text-accent hover:underline">{{ $item->mergedInto->reference() }}</a>
        @endif
    </x-slot:description>
</x-ui.page-header>

<div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
    <div class="space-y-6 lg:col-span-2">
        <x-ui.section title="Wording and release" description="What every customer reads. Name no company, and nothing only one of them told us.">
            @admincan('support.manage')
            <form method="POST" action="{{ route('admin.board.update', $item->id) }}" class="space-y-4">
                @csrf
                @include('admin.board.wording', ['item' => $item])
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <x-ui.field label="Released in" hint="The release that delivered it, such as 2026.11." error="released_in" optional>
                        <x-ui.input type="text" name="released_in" maxlength="80" dir="ltr" value="{{ old('released_in', $item->released_in) }}" />
                    </x-ui.field>
                    <x-ui.field label="Release notes link" error="release_url" optional>
                        <x-ui.input type="url" name="release_url" maxlength="255" dir="ltr" value="{{ old('release_url', $item->release_url) }}" />
                    </x-ui.field>
                </div>
                <x-ui.button>Save</x-ui.button>
            </form>
            @else
            <p class="whitespace-pre-line text-sm text-fg">{{ $item->summary }}</p>
            @endadmincan
        </x-ui.section>

        <x-ui.section title="Who asked" description="Admins only: customers never see which companies asked, nor what they wrote.">
            <div class="overflow-hidden rounded-card border border-line">
                <x-ui.table :primary="[1, 2, 0]">
                    <x-slot:head>
                        <x-ui.th>Request</x-ui.th>
                        <x-ui.th>Company</x-ui.th>
                        <x-ui.th>Subject</x-ui.th>
                    </x-slot:head>
                    @forelse ($item->requests as $request)
                    <tr class="hover:bg-sunken">
                        <td class="px-6 py-4 font-mono text-sm">
                            @if ($request->ticket)
                                <a href="{{ route('admin.support.show', $request->ticket_id) }}" class="text-accent hover:underline">{{ $request->ticket->reference() }}</a>
                            @endif
                        </td>
                        <td class="px-6 py-4 text-sm text-muted">{{ $request->ticket?->organization?->name }}</td>
                        <td class="px-6 py-4 text-sm text-fg">{{ $request->ticket?->subject }}</td>
                    </tr>
                    @empty
                    <x-ui.empty :columns="3">No requests in it</x-ui.empty>
                    @endforelse
                </x-ui.table>
            </div>
        </x-ui.section>

        <x-ui.section title="Updates">
            <ol class="space-y-3">
                @forelse ($item->updates as $update)
                    <li class="rounded-lg border border-line bg-surface p-4 text-sm">
                        <div class="mb-1 flex flex-wrap items-center gap-2 text-xs text-muted">
                            @if ($update->stage)
                                <x-ui.badge :tone="$update->stage->tone()" :label="$update->stage->label()" />
                            @endif
                            <x-ui.date :value="$update->created_at" time />
                        </div>
                        @if ($update->note)<p class="whitespace-pre-line text-fg">{{ $update->note }}</p>@endif
                        @if ($update->note_ar)<p class="mt-2 whitespace-pre-line text-fg" dir="rtl" lang="ar">{{ $update->note_ar }}</p>@endif
                    </li>
                @empty
                    <li class="text-sm text-muted">No updates yet.</li>
                @endforelse
            </ol>
        </x-ui.section>
    </div>

    <div class="space-y-6">
        @admincan('support.manage')
        @if (! $item->merged_into_id)
        <form method="POST" action="{{ route('admin.board.publish', $item->id) }}" class="space-y-3 rounded-card border border-line bg-surface p-4 shadow-card">
            @csrf
            <input type="hidden" name="published" value="{{ $item->published() ? '0' : '1' }}">
            <p class="text-sm text-muted">{{ $item->published() ? 'Every customer sees it on the board.' : 'A draft: only admins see it.' }}</p>
            <x-ui.button :variant="$item->published() ? 'secondary' : 'primary'" class="w-full">{{ $item->published() ? 'Unpublish' : 'Publish' }}</x-ui.button>
        </form>

        <form method="POST" action="{{ route('admin.board.stage', $item->id) }}" class="space-y-3 rounded-card border border-line bg-surface p-4 shadow-card">
            @csrf
            <x-ui.field label="Stage">
                <x-ui.select name="stage">
                    @foreach ($stages as $option)
                        <option value="{{ $option->value }}" @selected($item->stage === $option)>{{ $option->label() }}</option>
                    @endforeach
                </x-ui.select>
            </x-ui.field>
            <x-ui.field label="Public note in English" optional>
                <x-ui.textarea name="note" rows="3" maxlength="5000"></x-ui.textarea>
            </x-ui.field>
            <x-ui.field label="Public note in Arabic" optional>
                <x-ui.textarea name="note_ar" rows="3" maxlength="5000" dir="rtl" lang="ar"></x-ui.textarea>
            </x-ui.field>
            <p class="text-xs text-subtle">Shown on the board and on each company's own request; the companies that asked and everyone following are emailed.</p>
            <x-ui.button variant="secondary" class="w-full">Publish update</x-ui.button>
        </form>

        @if ($others->isNotEmpty())
        <form method="POST" action="{{ route('admin.board.merge', $item->id) }}" class="space-y-3 rounded-card border border-line bg-surface p-4 shadow-card"
              data-confirm-action="Merge"
              data-confirm="Merge {{ $item->reference() }} into the item chosen?"
              data-confirm-detail="Its requests, backers and followers move across, and it leaves the board.">
            @csrf
            <x-ui.field label="A duplicate of">
                <x-ui.select name="into_id">
                    @foreach ($others as $other)
                        <option value="{{ $other->item->id }}">{{ $other->item->reference() }} · {{ $other->item->title }}</option>
                    @endforeach
                </x-ui.select>
            </x-ui.field>
            <x-ui.button variant="secondary" class="w-full">Merge into it</x-ui.button>
        </form>
        @endif
        @endif
        @endadmincan
    </div>
</div>
@endsection
