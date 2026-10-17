{{--
    Pages of a long list, as ->links() draws them everywhere.

    Registered as the paginator's default view (AppServiceProvider), so the
    fourteen screens that call ->links() take this without being edited. The
    framework's own view was drawn in stock grey, with targets 38px tall and
    arrows that pointed the wrong way in Arabic.

    Previous and Next are words as well as arrows, and the arrows turn with
    the page's direction. The current page is aria-current="page".

    Receives $paginator and $elements from the framework.
--}}
@if ($paginator->hasPages())
    @php
        $cell = 'inline-flex min-h-11 min-w-11 items-center justify-center rounded-button px-3 text-sm font-medium tabular-nums transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus';
    @endphp
    <nav role="navigation" aria-label="{{ __('Pages of results') }}" class="mt-4 flex flex-wrap items-center justify-between gap-3">
        @if (method_exists($paginator, 'total'))
            <p class="text-sm text-subtle">
                {{ __('Showing :first to :last of :total', ['first' => $paginator->firstItem() ?? 0, 'last' => $paginator->lastItem() ?? 0, 'total' => $paginator->total()]) }}
            </p>
        @endif

        <div class="flex flex-wrap items-center gap-1">
            @if ($paginator->onFirstPage())
                <span class="{{ $cell }} text-subtle opacity-50" aria-disabled="true">
                    <svg class="size-4 rtl:rotate-180" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15 18l-6-6 6-6" /></svg>
                    <span class="ms-1">{{ __('Previous') }}</span>
                </span>
            @else
                <a href="{{ $paginator->previousPageUrl() }}" rel="prev" class="{{ $cell }} text-fg hover:bg-sunken">
                    <svg class="size-4 rtl:rotate-180" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15 18l-6-6 6-6" /></svg>
                    <span class="ms-1">{{ __('Previous') }}</span>
                </a>
            @endif

            @isset($elements)
                @foreach ($elements as $element)
                    @if (is_string($element))
                        <span class="{{ $cell }} hidden text-subtle sm:inline-flex" aria-hidden="true">{{ $element }}</span>
                    @endif

                    @if (is_array($element))
                        @foreach ($element as $page => $url)
                            @if ($page == $paginator->currentPage())
                                <span aria-current="page" class="{{ $cell }} hidden bg-action text-on-action sm:inline-flex">{{ $page }}</span>
                            @else
                                <a href="{{ $url }}" class="{{ $cell }} hidden text-fg hover:bg-sunken sm:inline-flex" aria-label="{{ __('Page :page', ['page' => $page]) }}">{{ $page }}</a>
                            @endif
                        @endforeach
                    @endif
                @endforeach
            @endisset

            @if ($paginator->hasMorePages())
                <a href="{{ $paginator->nextPageUrl() }}" rel="next" class="{{ $cell }} text-fg hover:bg-sunken">
                    <span class="me-1">{{ __('Next') }}</span>
                    <svg class="size-4 rtl:rotate-180" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 6l6 6-6 6" /></svg>
                </a>
            @else
                <span class="{{ $cell }} text-subtle opacity-50" aria-disabled="true">
                    <span class="me-1">{{ __('Next') }}</span>
                    <svg class="size-4 rtl:rotate-180" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 6l6 6-6 6" /></svg>
                </span>
            @endif
        </div>
    </nav>
@endif
