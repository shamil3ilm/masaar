@extends('layouts.guest')

@section('title', 'Verify your email')
@section('subtitle', 'One more step')

@section('content')
<p class="mb-6 text-sm text-gray-600 dark:text-gray-300">
    We sent a link to <strong>{{ auth()->user()->email }}</strong>. Open it to verify that the address is yours -
    password resets and notices about your company's filing go there.
</p>

<form method="POST" action="{{ route('verification.send') }}" class="mb-4">
    @csrf
    <x-ui.button variant="outline" class="w-full">Send the link again</x-ui.button>
</form>

<form method="POST" action="{{ route('logout') }}">
    @csrf
    <x-ui.button variant="outline" class="w-full">Sign out</x-ui.button>
</form>
@endsection
