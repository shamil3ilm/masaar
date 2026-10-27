{{--
    A customer's statement as a PDF (BooksPdf), in the reader's language.

    @param \App\Domains\Organization\Models\Organization $organization
    @param \App\Domains\Invoice\Models\Customer $customer
    @param array<string, mixed> $statement
    @param bool $rtl
--}}
<html lang="{{ app()->getLocale() }}" dir="{{ $rtl ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <style>
        body { font-size: 9pt; }
        h1 { font-size: 14pt; margin: 0 0 4pt; }
        table { width: 100%; border-collapse: collapse; margin-top: 8pt; }
        th, td { border-bottom: 0.5pt solid gray; padding: 3pt; text-align: {{ $rtl ? 'right' : 'left' }}; }
        .n { text-align: {{ $rtl ? 'left' : 'right' }}; direction: ltr; }
        .t td { font-weight: bold; }
    </style>
</head>
<body>
    <h1>{{ __('Statement of account') }}</h1>
    <p><strong>{{ $organization->name }}</strong>@if ($organization->vat_number) - {{ __('VAT number') }} {{ $organization->vat_number }}@endif</p>
    <p>{{ __('To') }}: <strong>{{ $rtl && $customer->name_ar ? $customer->name_ar : $customer->name }}</strong>@if ($customer->name_ar) / {{ $rtl ? $customer->name : $customer->name_ar }}@endif @if ($customer->vat_number) - {{ __('VAT number') }} {{ $customer->vat_number }}@endif</p>
    <p>{{ __('From :from to :to, in :currency.', ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'currency' => $statement['currency']]) }}</p>
    <table>
        <tr><th scope="col">{{ __('Date') }}</th><th scope="col">{{ __('Entry') }}</th><th scope="col">{{ __('Reference') }}</th><th scope="col" class="n">{{ __('Debit') }}</th><th scope="col" class="n">{{ __('Credit') }}</th><th scope="col" class="n">{{ __('Balance') }}</th></tr>
        <tr class="t"><td></td><td>{{ __('Opening balance') }}</td><td></td><td></td><td></td><td class="n">{{ \App\Domains\Books\Services\Purchases::shown($statement['opening']) }}</td></tr>
        @foreach ($statement['lines'] as $line)
            <tr>
                <td>{{ $line['date'] }}</td>
                <td>{{ $line['kind'] }}</td>
                <td dir="ltr">{{ $line['reference'] }}</td>
                <td class="n">{{ $line['debit'] > 0 ? \App\Domains\Books\Services\Purchases::shown($line['debit']) : '' }}</td>
                <td class="n">{{ $line['credit'] > 0 ? \App\Domains\Books\Services\Purchases::shown($line['credit']) : '' }}</td>
                <td class="n">{{ \App\Domains\Books\Services\Purchases::shown($line['balance']) }}</td>
            </tr>
        @endforeach
        <tr class="t"><td></td><td>{{ __('Closing balance') }}</td><td></td><td class="n">{{ \App\Domains\Books\Services\Purchases::shown($statement['debits']) }}</td><td class="n">{{ \App\Domains\Books\Services\Purchases::shown($statement['credits']) }}</td><td class="n">{{ \App\Domains\Books\Services\Purchases::shown($statement['closing']) }} {{ $statement['currency'] }}</td></tr>
    </table>
</body>
</html>
