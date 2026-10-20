{{--
    A feature request's place on the board: the item it is in, or the ways
    to triage it - a new item worded here, an item already there that it
    duplicates, or a customization when it is work for this customer alone.
--}}
<div class="space-y-3 rounded-card border border-line bg-surface p-4 shadow-card">
    <h2 class="text-sm font-semibold text-fg">Feature board</h2>
    @if ($boardItem)
        <p class="text-sm text-muted">
            In <a href="{{ route('admin.board.show', $boardItem->id) }}" class="text-accent hover:underline">{{ $boardItem->reference() }} · {{ $boardItem->title }}</a>
            ({{ $boardItem->published() ? 'published' : 'a draft' }}).
        </p>
    @else
        @admincan('support.manage')
        <x-ui.disclosure summary="Put it on the board as a new item">
            <form method="POST" action="{{ route('admin.board.store', $ticket->id) }}" class="space-y-3 pt-3">
                @csrf
                @include('admin.board.wording', ['item' => null, 'title' => $ticket->subject, 'category' => $ticket->featureRequest?->category])
                <input type="hidden" name="publish" value="0">
                <x-ui.checkbox name="publish" value="1">Publish now</x-ui.checkbox>
                <x-ui.button variant="secondary" class="w-full">Make the item</x-ui.button>
            </form>
        </x-ui.disclosure>

        @if ($boardItems->isNotEmpty())
        <form method="POST" action="{{ route('admin.board.link', $ticket->id) }}" class="space-y-3">
            @csrf
            <x-ui.field label="Or it asks for the same as">
                <x-ui.select name="item_id">
                    @foreach ($boardItems as $standing)
                        <option value="{{ $standing->item->id }}">{{ $standing->item->reference() }} · {{ $standing->item->title }}</option>
                    @endforeach
                </x-ui.select>
            </x-ui.field>
            <x-ui.button variant="secondary" class="w-full">Add it to that item</x-ui.button>
        </form>
        @endif

        <form method="POST" action="{{ route('admin.board.convert', $ticket->id) }}" class="space-y-3"
              data-confirm-action="Make it a customization"
              data-confirm="Make {{ $ticket->reference() }} a customization?"
              data-confirm-detail="It leaves the board's triage, and its work and price are agreed on this ticket.">
            @csrf
            <x-ui.field label="Or it is work for this customer alone" hint="A note the customer reads. Optional." optional>
                <x-ui.textarea name="note" rows="2" maxlength="5000"></x-ui.textarea>
            </x-ui.field>
            <x-ui.button variant="secondary" class="w-full">Make it a customization</x-ui.button>
        </form>
        @endadmincan
    @endif
</div>
