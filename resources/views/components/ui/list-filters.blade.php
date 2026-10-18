@props(['list'])

{{--
    The filters, order and page size of a history list, the chips that say
    which filters are on, the person's saved views, and the CSV.

    Every list draws this from its ListState rather than markup of its own,
    so each one sorts, narrows, saves and exports the same way. The form is a
    GET to the list itself: the filters live in the query string, so a link
    to a filtered list can be bookmarked or sent to someone.

    On a phone the filters fold away like any optional part of a page
    (x-ui.section collapse) and open with one tap; the chips stay in view,
    so what is narrowing the list is never hidden with the controls.

    list  an App\Http\Listing\ListState.
--}}
@php
    $chips = $list->chips();
    $active = $list->count();
    // Unique per drawing: two lists on a page, or the style guide's four
    // previews, must not share the ids the labels point at.
    $uid = 'list-'.\Illuminate\Support\Str::lower(\Illuminate\Support\Str::random(6));
@endphp

<div {{ $attributes->merge(['class' => 'mb-6 space-y-3']) }}>
    <x-ui.section collapse :title="__('Filters')"
                  :description="$active > 0 ? __('Filters on: :count', ['count' => $active]) : __('Sort, narrow and page the list')">
        <form method="GET" action="{{ $list->url }}" role="search" aria-label="{{ __('Filter the list') }}" class="space-y-4">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                @foreach ($list->spec->filters as $filter)
                    @include($filter->view(), ['filter' => $filter, 'list' => $list])
                @endforeach

                <x-ui.field :label="__('Sort by')">
                    <x-ui.select name="sort">
                        @foreach ($list->spec->sorts as $key => $sort)
                            <option value="{{ $key }}" @selected($list->sort === $key)>{{ $sort->label }}</option>
                        @endforeach
                    </x-ui.select>
                </x-ui.field>

                <x-ui.field :label="__('Order')">
                    <x-ui.select name="dir">
                        <option value="desc" @selected($list->direction === 'desc')>{{ __('Descending') }}</option>
                        <option value="asc" @selected($list->direction === 'asc')>{{ __('Ascending') }}</option>
                    </x-ui.select>
                </x-ui.field>

                <x-ui.field :label="__('Rows per page')">
                    <x-ui.select name="per_page">
                        @foreach ($list->pageSizes() as $size)
                            <option value="{{ $size }}" @selected($list->perPage === $size)>{{ $size }}</option>
                        @endforeach
                    </x-ui.select>
                </x-ui.field>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <x-ui.button>{{ __('Apply filters') }}</x-ui.button>
                @if ($list->filtered())
                    <x-ui.button variant="ghost" :href="$list->clearUrl()">{{ __('Clear all filters') }}</x-ui.button>
                @endif
            </div>
        </form>

        @if ($list->saveUrl)
            <form method="POST" action="{{ $list->saveUrl }}" class="mt-5 flex flex-wrap items-end gap-2 border-t border-line pt-5">
                @csrf
                <input type="hidden" name="list" value="{{ $list->spec->key }}">
                <input type="hidden" name="query" value="{{ $list->queryString() }}">
                <x-ui.field :label="__('Save these filters as')" :hint="__('Only you see your saved views.')" class="min-w-56 flex-1">
                    <x-ui.input name="name" maxlength="60" required :placeholder="__('For example: This month\'s refunds')" />
                </x-ui.field>
                <x-ui.button variant="secondary">{{ __('Save view') }}</x-ui.button>
            </form>
        @endif
    </x-ui.section>

    @if ($chips !== [] || $list->views !== [] || $list->exportUrl)
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex flex-wrap items-center gap-2">
                @if ($chips !== [])
                    <span class="text-sm font-medium text-muted" id="{{ $uid }}-active">{{ __('Filtered by') }}</span>
                    <ul class="flex flex-wrap gap-2" aria-labelledby="{{ $uid }}-active">
                        @foreach ($chips as $chip)
                            <li>
                                <a href="{{ $chip->removeUrl }}"
                                   aria-label="{{ __('Remove the filter :filter', ['filter' => $chip->label.': '.$chip->value]) }}"
                                   class="inline-flex min-h-11 items-center gap-2 rounded-full border border-accent-line bg-accent-soft px-3 text-sm text-fg hover:border-accent focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus">
                                    <span class="text-muted">{{ $chip->label }}:</span>
                                    <span class="font-medium">{{ $chip->value }}</span>
                                    <svg class="size-4 text-subtle" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" d="M6 6l12 12M18 6L6 18" /></svg>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                    <a href="{{ $list->clearUrl() }}" class="inline-flex min-h-11 items-center text-sm font-medium text-accent underline-offset-4 hover:text-accent-strong hover:underline">{{ __('Clear all') }}</a>
                @endif
            </div>

            @if ($list->exportUrl)
                <x-ui.button variant="secondary" :href="$list->exportUrl">
                    <svg class="size-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v12m0 0l-4-4m4 4l4-4M4 20h16" /></svg>
                    {{ __('Export CSV') }}
                </x-ui.button>
            @endif
        </div>
    @endif

    @if ($list->views !== [])
            <div class="flex flex-wrap items-center gap-2">
                <span class="text-sm font-medium text-muted" id="{{ $uid }}-views">{{ __('Saved views') }}</span>
                <ul class="flex flex-wrap gap-2" aria-labelledby="{{ $uid }}-views">
                    @foreach ($list->views as $view)
                        <li class="inline-flex items-center rounded-full border border-line bg-surface">
                            <a href="{{ $view['url'] }}" class="inline-flex min-h-11 items-center ps-3 pe-1 text-sm font-medium text-accent hover:text-accent-strong">{{ $view['name'] }}</a>
                            <form method="POST" action="{{ $view['delete'] }}" data-confirm="{{ __('Delete the saved view :name?', ['name' => $view['name']]) }}">
                                @csrf
                                <button type="submit" aria-label="{{ __('Delete the saved view :name', ['name' => $view['name']]) }}"
                                        class="inline-flex size-11 items-center justify-center rounded-full text-subtle hover:bg-sunken hover:text-danger focus-visible:outline-2 focus-visible:outline-focus">
                                    <svg class="size-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" d="M6 6l12 12M18 6L6 18" /></svg>
                                </button>
                            </form>
                        </li>
                    @endforeach
                </ul>
            </div>
    @endif
</div>
