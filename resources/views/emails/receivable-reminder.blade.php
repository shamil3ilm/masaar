@extends('emails.layout')

@section('title', $kind === 'overdue' ? __('An invoice is past due') : __('An invoice falls due soon'))

{{--
    In both languages, the reader's first ($locales), each in its own
    direction: whoever pays may read either.
--}}
@section('content')
    @foreach ($locales as $locale)
        @php($rtl = $locale === 'ar')
        <div lang="{{ $locale }}" dir="{{ $rtl ? 'rtl' : 'ltr' }}" style="text-align:{{ $rtl ? 'right' : 'left' }};{{ $loop->last ? '' : 'margin:0 0 24px;padding:0 0 24px;border-bottom:1px solid #e3e5ea;' }}{{ $rtl ? "font-family:Tahoma,'Segoe UI',Arial,sans-serif;" : '' }}">
            <h1 style="margin:0 0 12px;font-size:22px;line-height:1.3;font-weight:600;">
                {{ $kind === 'overdue'
                    ? __('Invoices to :organization are past due', ['organization' => $organizationName], $locale)
                    : __('Invoices to :organization fall due soon', ['organization' => $organizationName], $locale) }}
            </h1>
            <p style="margin:0 0 16px;color:#4a5170;">
                {{ $kind === 'overdue'
                    ? __('These invoices are past their due date and still unpaid. Please pay them, quoting the invoice number.', [], $locale)
                    : __('These invoices fall due soon. Please pay them by their due date, quoting the invoice number.', [], $locale) }}
            </p>
            <table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;margin:0 0 16px;border-collapse:collapse;">
                <tr>
                    <th scope="col" style="padding:6px 0;text-align:{{ $rtl ? 'right' : 'left' }};font-size:13px;color:#4a5170;">{{ __('Invoice', [], $locale) }}</th>
                    <th scope="col" style="padding:6px 0;text-align:{{ $rtl ? 'right' : 'left' }};font-size:13px;color:#4a5170;">{{ __('Due', [], $locale) }}</th>
                    <th scope="col" style="padding:6px 0;text-align:{{ $rtl ? 'left' : 'right' }};font-size:13px;color:#4a5170;">{{ __('Balance', [], $locale) }}</th>
                </tr>
                @foreach ($invoices as $invoice)
                    <tr>
                        <td style="padding:6px 0;border-top:1px solid #e3e5ea;" dir="ltr">{{ $invoice['number'] }}</td>
                        <td style="padding:6px 0;border-top:1px solid #e3e5ea;" dir="ltr">{{ $invoice['due_date'] }}</td>
                        <td style="padding:6px 0;border-top:1px solid #e3e5ea;text-align:{{ $rtl ? 'left' : 'right' }};" dir="ltr">{{ $invoice['balance'] }} {{ $invoice['currency'] }}</td>
                    </tr>
                @endforeach
            </table>
            @if ($accounts !== [])
                <p style="margin:0 0 8px;color:#4a5170;">{{ __('Pay by bank transfer to:', [], $locale) }}</p>
                @foreach ($accounts as $account)
                    <p style="margin:0 0 4px;"><span>{{ $account['bank_name'] }}</span> · {{ $account['account_name'] }} · <span dir="ltr">{{ \App\Domains\Billing\Settings\Iban::format($account['iban']) }}</span></p>
                @endforeach
            @endif
            @include('emails.partials.button', ['href' => $statementUrl, 'label' => __('See your statement', [], $locale)])
        </div>
    @endforeach
@endsection
