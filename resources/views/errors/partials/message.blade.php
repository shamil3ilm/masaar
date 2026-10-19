{{--
    What every error page says, in one shape: the status code small, what
    happened as the heading, what to do next in a line, and the way out.

    @param string $code
    @param string $title
    @param string|null $line   plain text; or the page fills the 'line' section
    @param array<int, array{0: string, 1: string, 2?: string}> $actions  [label, href, variant]
--}}
<div class="mx-auto flex min-h-[60vh] w-full max-w-page items-center px-4 py-16 sm:px-6">
    <div class="max-w-xl">
        <p class="text-eyebrow font-semibold uppercase text-accent"><span dir="ltr">{{ $code }}</span></p>
        <h1 class="mt-2 text-title font-semibold">{{ $title }}</h1>
        <p class="mt-4 text-lead text-muted" data-error-line>
            @hasSection('line')
                @yield('line')
            @else
                {{ $line ?? '' }}
            @endif
        </p>
        <div class="mt-8 flex flex-wrap gap-3">
            @foreach ($actions as $action)
                <x-ui.button size="lg" :href="$action[1]" :variant="$action[2] ?? 'primary'">{{ $action[0] }}</x-ui.button>
            @endforeach
        </div>
    </div>
</div>
