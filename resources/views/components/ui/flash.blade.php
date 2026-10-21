{{--
    What the last action left to say: the session's success, warning and
    error, and the validation errors of a form sent back.

    One place for both layouts. The admin console's copy once rendered only
    two of the three keys, so a 'warning' flashed there - the action worked,
    read this - was dropped without a trace.

    Amber rather than green for a warning: the action succeeded but the
    outcome is partial. Issuing without a certificate writes a valid invoice
    and files nothing, and a taxpayer reading only a green banner would
    believe the authority has it.
--}}
@if (session('success') || session('error') || session('warning') || $errors->any())
<div {{ $attributes->merge(['class' => 'space-y-3']) }}>
    @if (session('success'))
        <x-ui.alert tone="success">{{ session('success') }}</x-ui.alert>
    @endif

    @if (session('error'))
        <x-ui.alert tone="danger">{{ session('error') }}</x-ui.alert>
    @endif

    @if (session('warning'))
        <x-ui.alert tone="warning">{{ session('warning') }}</x-ui.alert>
    @endif

    @if ($errors->any())
        <x-ui.alert tone="danger" :title="__('Please correct the following:')">
            <ul class="list-disc space-y-1 ps-5">
                {{-- Once each: two fields refused for one reason say it once. --}}
                @foreach (array_unique($errors->all()) as $message)
                    <li>{{ $message }}</li>
                @endforeach
            </ul>
        </x-ui.alert>
    @endif
</div>
@endif
