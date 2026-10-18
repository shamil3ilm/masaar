@props(['items'])

{{--
    The way back up: where this screen sits.

    items  list of ['label' => ..., 'href' => ...]; the last is this screen
           and is not a link (aria-current="page").

    The separator is a chevron, turned in Arabic so it points the way the
    trail reads.
--}}
<nav aria-label="{{ __('Breadcrumb') }}" {{ $attributes }}>
    <ol class="flex flex-wrap items-center gap-1 text-sm text-subtle">
        @foreach ($items as $item)
            <li class="inline-flex items-center gap-1">
                @if (! $loop->last && ! empty($item['href']))
                    <a href="{{ $item['href'] }}" class="inline-flex min-h-11 items-center rounded hover:text-fg hover:underline underline-offset-4">{{ $item['label'] }}</a>
                    <svg class="size-4 shrink-0 rtl:rotate-180" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 6l6 6-6 6" /></svg>
                @else
                    <span aria-current="page" class="font-medium text-fg">{{ $item['label'] }}</span>
                @endif
            </li>
        @endforeach
    </ol>
</nav>
