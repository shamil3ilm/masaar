{{--
    Flash messages.

    Included from layouts/portal.blade.php, so every page that extends it
    can show one. This sat in two views for a while, which is how
    PortalInvoiceDraftController's 'error' came to be written, redirected
    with, and never rendered: back() from a failed draft save lands on the
    create form, which was not one of the two.
--}}
@if (session('success'))
    <div class="mb-6 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">
        {{ session('success') }}
    </div>
@endif

@if (session('error'))
    <div class="mb-6 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
        {{ session('error') }}
    </div>
@endif

{{--
    Amber rather than green or red: the action succeeded but the outcome is
    partial. Issuing without a certificate writes a valid invoice and files
    nothing, and a taxpayer reading only a green banner would believe the
    authority has it.
--}}
@if (session('warning'))
    <div class="mb-6 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
        {{ session('warning') }}
    </div>
@endif

@if ($errors->any())
    <div class="mb-6 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
        <p class="font-semibold">Please correct the following:</p>
        <ul class="mt-2 list-disc space-y-1 pl-5">
            @foreach ($errors->all() as $message)
                <li>{{ $message }}</li>
            @endforeach
        </ul>
    </div>
@endif
