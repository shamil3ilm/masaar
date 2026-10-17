@extends('layouts.guest')

@section('title', __('Two-factor is on'))
@section('subtitle', __('Save these recovery codes somewhere safe. Each one signs you in once if you lose your phone.'))

@section('content')
<x-ui.alert tone="warning">
    <strong>{{ __('They are shown only now') }}</strong> {{ __('- they cannot be displayed again.') }}
</x-ui.alert>

<ul class="my-6 grid grid-cols-2 gap-2 rounded-tile border border-line bg-sunken p-4 font-mono text-sm tabular-nums" dir="ltr">
    @foreach ($codes as $code)
        <li>{{ $code }}</li>
    @endforeach
</ul>

<p class="mb-6 text-xs text-subtle">
    {{ __('Lost them and your phone? Another platform admin can reset your two-factor from your page under Admin → Users.') }}
</p>

<x-ui.button size="lg" class="w-full" :href="route('admin.dashboard')">{{ __('I have saved them - continue') }}</x-ui.button>
@endsection
