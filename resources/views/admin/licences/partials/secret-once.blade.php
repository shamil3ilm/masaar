{{--
    The one moment the plaintext secret exists.

    The row keeps only api_secret_hash, so this is flashed by store() and
    nothing can read it back. Saying so here is the point: an admin who assumes
    they can look it up later hands the partner nothing, and the only remedy is
    regenerating, which breaks the integration the partner has already wired.
--}}
@if (session('api_secret'))
    <x-ui.alert tone="warning" title="Copy this secret now" class="mb-6">
        <p>
            It is stored only as a hash. Once you leave this page it cannot be
            shown again — the only remedy is to regenerate it, which breaks the
            partner's current integration.
        </p>
        <code class="mt-3 block break-all rounded bg-surface px-3 py-2 font-mono text-sm text-fg">{{ session('api_secret') }}</code>
    </x-ui.alert>
@endif
