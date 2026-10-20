{{-- Reconciliation's parts, one a page: the current one is the primary action. --}}
<nav aria-label="{{ __('Reconciliation') }}" class="mb-6 flex flex-wrap gap-2">
    @foreach (['imports' => __('Statements'), 'lines' => __('Lines'), 'exceptions' => __('Unmatched, short and over'), 'reports' => __('Report')] as $part => $label)
        <x-ui.button :href="route('admin.finance.reconciliation.'.$part)" :variant="$current === $part ? 'primary' : 'secondary'" size="sm"
                     :aria-current="$current === $part ? 'page' : null">{{ $label }}</x-ui.button>
    @endforeach
</nav>
