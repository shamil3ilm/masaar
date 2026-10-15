{{--
    The one moment the plaintext secret exists.

    The row keeps only api_secret_hash, so this is flashed by store() and
    nothing can read it back. Saying so here is the point: an admin who assumes
    they can look it up later hands the partner nothing, and the only remedy is
    regenerating, which breaks the integration the partner has already wired.
--}}
@if (session('api_secret'))
    <div class="mb-6 rounded-lg border border-amber-300 dark:border-amber-700 bg-amber-50 dark:bg-amber-950/40 p-4">
        <p class="font-semibold text-amber-900 dark:text-amber-200">Copy this secret now</p>
        <p class="mt-1 text-sm text-amber-800 dark:text-amber-200">
            It is stored only as a hash. Once you leave this page it cannot be
            shown again — the only remedy is to regenerate it, which breaks the
            partner's current integration.
        </p>
        <code class="mt-3 block break-all rounded bg-white dark:bg-gray-900 px-3 py-2 font-mono text-sm">{{ session('api_secret') }}</code>
    </div>
@endif
