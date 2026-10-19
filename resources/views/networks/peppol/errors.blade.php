{{--
    What a provider, the buyer's provider or the authority found wrong with
    an e-invoice sent over the network, one line each: the rule, how badly, where, and in words
    (PeppolDocument::errorList).

    @param array $errors  list of {code, severity, field, message}
--}}
<ul class="mt-1 space-y-2 text-xs">
    @foreach ($errors as $error)
        <li class="break-words" data-peppol-error>
            @if ($error['code'])
                <span class="font-mono font-semibold {{ $error['severity'] === 'warning' ? 'text-warning' : 'text-danger' }}">{{ $error['code'] }}</span>
            @endif
            <span class="{{ $error['severity'] === 'warning' ? 'text-warning' : 'text-danger' }}">{{ $error['message'] }}</span>
            @if ($error['field'])
                <span class="block font-mono text-muted">{{ __('At :field', ['field' => $error['field']]) }}</span>
            @endif
        </li>
    @endforeach
</ul>
