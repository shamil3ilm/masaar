{{--
    Where the organization's country requires Arabic on its invoices: how
    many of the names its invoices take are missing, and the grace period -
    warn instead of refuse until a date - a platform admin can give it
    while they are filled in (LanguageGrace).

    Every admin role reads it. Only features.manage sets the grace; a reason
    is required, and the change is audited with the state before and after.

    @param array $arabic  ArabicNameReport::summary()
--}}
<div id="arabic-text" class="bg-surface rounded-card border border-line shadow-card mb-6">
    <div class="p-6 border-b">
        <h3 class="text-lg font-semibold text-fg">Arabic text</h3>
        <p class="mt-1 text-sm text-muted">
            Its invoices must carry Arabic: each line's description, any notes, and the buyer's name on a standard invoice. Missing, a draft is refused unless a grace period below says to warn instead.
        </p>
    </div>
    <div class="grid grid-cols-1 gap-6 p-6 md:grid-cols-2">
        <div class="space-y-2 text-sm">
            <p class="font-medium text-fg">Missing Arabic names</p>
            <ul class="space-y-1 text-muted">
                <li>Its own name: {{ $arabic['seller'] ? 'missing' : 'given' }}</li>
                <li>Customers: {{ number_format($arabic['customers']) }}</li>
                <li>Products: {{ number_format($arabic['products']) }}</li>
            </ul>
            <a href="{{ route('admin.organizations.missing-arabic', $organization->id) }}" class="inline-flex min-h-11 items-center text-accent hover:text-accent-strong">See the list</a>
        </div>

        <div class="space-y-3 text-sm">
            <p class="font-medium text-fg">Grace period</p>
            @if ($arabic['grace'])
                <p class="text-fg">
                    <x-ui.badge :tone="$arabic['grace']['active'] ? 'warning' : 'neutral'" :label="$arabic['grace']['active'] ? 'Warning only' : 'Ended'" />
                    Until {{ $arabic['grace']['until'] }}, set by {{ $arabic['grace']['by'] ?? 'unknown' }}: {{ $arabic['grace']['reason'] }}
                </p>
            @else
                <p class="text-muted">None. Missing Arabic text is refused.</p>
            @endif

            @admincan('features.manage')
            <form method="POST" action="{{ route('admin.organizations.language-grace', $organization->id) }}" class="space-y-2"
                  data-confirm-action="Save"
                  data-confirm="Change the Arabic text grace period for {{ $organization->name }}? It takes effect on the next invoice and is recorded with your reason.">
                @csrf
                <x-ui.field label="Warn instead of refuse until" hint="Leave empty to refuse again now. It ends on its own after this date.">
                    <x-ui.input type="date" name="until" value="{{ $arabic['grace']['active'] ?? false ? $arabic['grace']['until'] : '' }}" />
                </x-ui.field>
                <x-ui.field label="Reason">
                    <x-ui.input name="reason" maxlength="500" required />
                </x-ui.field>
                <x-ui.button variant="secondary">Save</x-ui.button>
            </form>
            @endadmincan
        </div>
    </div>
</div>
