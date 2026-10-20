{{--
    What every error page says, in one shape: the status code small, what
    happened as the heading, what to do next in a line, and the way out.

    @param string $code
    @param string $title
    @param string|null $line   plain text; or the page fills the 'line' section
    @param array<int, array{0: string, 1: string, 2?: string}> $actions  [label, href, variant]
    @param bool $bearings  signed in, lead with the way back to their dashboard

    Bearings: someone signed in who meets a refusal or a missing page keeps
    their place. The way back goes to the area they were in if they may be
    there, and otherwise to the one that is theirs - the console for a
    platform admin, the portal for everyone else. A 404 for an address no
    route matches is drawn without a session, so it reads as signed out.
--}}
@php
    $user = ($bearings ?? false) ? request()->user() : null;

    if ($user instanceof \App\Domains\Auth\Models\User) {
        $console = $user->isPlatformAdmin();
        $dashboard = match (true) {
            $console && request()->is('admin', 'admin/*') => route('admin.dashboard'),
            ! $console || request()->is('portal', 'portal/*') => route('portal.dashboard'),
            default => route('admin.dashboard'),
        };

        $actions = [
            [__('Back to the dashboard'), $dashboard],
            ...array_map(fn (array $action): array => [$action[0], $action[1], 'secondary'], $actions),
        ];
    }
@endphp
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
        <div class="mt-8 flex flex-wrap gap-3" data-error-actions>
            @foreach ($actions as $action)
                <x-ui.button size="lg" :href="$action[1]" :variant="$action[2] ?? 'primary'">{{ $action[0] }}</x-ui.button>
            @endforeach
        </div>
    </div>
</div>
