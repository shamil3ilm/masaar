{{--
    Flash messages.

    Nothing in resources/views rendered these before: AdminController::processQueue
    has flashed 'success' for as long as it has existed and no page has ever
    shown it. Every portal action that redirects depends on this being here.
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
