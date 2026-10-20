@extends('layouts.guest')

@section('title', __('Card payment simulator'))

@section('subtitle', __('This page stands in for a card provider while none is contracted. No card is charged and no money moves.'))

@section('content')
<x-ui.card>
    <dl class="space-y-2 text-sm">
        <div class="flex justify-between gap-4"><dt class="text-muted">{{ __('Amount') }}</dt><dd class="font-semibold text-fg"><x-ui.amount :value="$session['amount']" :currency="$session['currency']" /></dd></div>
        <div class="flex justify-between gap-4"><dt class="text-muted">{{ __('For') }}</dt><dd class="text-fg">{{ $session['description'] }}</dd></div>
        <div class="flex justify-between gap-4"><dt class="text-muted">{{ __('Reference') }}</dt><dd class="text-fg" dir="ltr">{{ $session['reference'] }}</dd></div>
    </dl>

    @if ($session['state'] === 'pending')
        <form method="POST" action="{{ route('billing.simulator.decide', $sessionId) }}" class="mt-6 flex flex-wrap gap-3">
            @csrf
            <x-ui.button name="outcome" value="approve">{{ __('Approve the payment') }}</x-ui.button>
            <x-ui.button variant="secondary" name="outcome" value="decline">{{ __('Decline the payment') }}</x-ui.button>
        </form>
    @else
        <x-ui.alert tone="info" class="mt-6">{{ __('This payment has been decided already.') }}</x-ui.alert>
    @endif
</x-ui.card>
@endsection
