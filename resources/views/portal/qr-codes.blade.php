@extends('layouts.portal')

@section('title', __('QR codes'))

@section('content')
@php
    $kinds = [
        \App\Domains\Invoice\Models\BusinessQrCode::CONTACT => __('Contact card'),
        \App\Domains\Invoice\Models\BusinessQrCode::PAYMENT => __('Payment details'),
        \App\Domains\Invoice\Models\BusinessQrCode::LINK => __('Location or website'),
    ];
@endphp
<div class="mb-6">
    <h2 class="text-2xl font-bold text-gray-800 dark:text-gray-100">{{ __('QR codes') }}</h2>
    <p class="text-gray-500 dark:text-gray-400">
        {{ __('Codes a customer scans: your contact card, your payment details, your location or website, or a link that opens one invoice. Download them for a sign or a card, or print one on your invoices beside the ZATCA QR code, which never changes.') }}
    </p>
</div>

<div class="max-w-3xl space-y-6">
    <section class="rounded-lg bg-white dark:bg-gray-900 shadow">
        <h3 class="border-b p-4 font-semibold text-gray-800 dark:text-gray-100">{{ __('Your QR codes') }}</h3>
        @forelse ($codes as $item)
            <div class="flex flex-wrap items-center gap-4 border-b p-4" data-qr-code>
                {{-- Unescaped: markup QrCodeRenderer drew. --}}
                <div class="w-28 bg-white dark:bg-white p-1 [&_svg]:h-auto [&_svg]:w-full">{!! $item['svg'] !!}</div>
                <div class="min-w-0 flex-1">
                    <p class="font-medium text-gray-800 dark:text-gray-100">{{ $item['code']->label }}</p>
                    <p class="text-sm text-gray-500 dark:text-gray-400">{{ $kinds[$item['code']->kind] ?? $item['code']->kind }}</p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <x-ui.button variant="outline" :href="route('portal.qr-codes.download', [$item['code']->id, 'png'])">PNG</x-ui.button>
                    <x-ui.button variant="outline" :href="route('portal.qr-codes.download', [$item['code']->id, 'svg'])">SVG</x-ui.button>
                    @if ($admin)
                        <form method="POST" action="{{ route('portal.qr-codes.destroy', $item['code']->id) }}">
                            @csrf
                            <x-ui.button variant="danger">{{ __('Delete') }}</x-ui.button>
                        </form>
                    @endif
                </div>
            </div>
        @empty
            <p class="p-4 text-sm text-gray-500 dark:text-gray-400">{{ __('No QR codes yet.') }}</p>
        @endforelse
    </section>

    @if ($admin)
        <section class="rounded-lg bg-white dark:bg-gray-900 shadow">
            <h3 class="border-b p-4 font-semibold text-gray-800 dark:text-gray-100">{{ __('Create a QR code') }}</h3>
            @foreach (['contact' => ['name' => __('Business name'), 'phone' => __('Phone'), 'email' => __('Email'), 'website' => __('Website'), 'address' => __('Address')],
                       'payment' => ['bank' => __('Bank'), 'iban' => __('IBAN'), 'amount' => __('Amount (empty: the invoice total)'), 'currency' => __('Currency'), 'reference' => __('Reference (empty: the invoice number)')],
                       'link' => ['url' => __('Link to your location or website')]] as $kind => $fields)
                @php($bag = $errors->getBag($kind))
                <form method="POST" action="{{ route('portal.qr-codes.store') }}" class="border-b p-4">
                    @csrf
                    <input type="hidden" name="kind" value="{{ $kind }}">
                    <h4 class="mb-2 font-medium text-gray-800 dark:text-gray-100">{{ $kinds[$kind] }}</h4>
                    @if ($bag->any())
                        <ul class="mb-2 list-disc ps-5 text-sm text-red-700 dark:text-red-300">
                            @foreach ($bag->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    @endif
                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <x-ui.field :label="__('Label printed under it')">
                            <x-ui.input type="text" name="label" maxlength="80" />
                        </x-ui.field>
                        @foreach ($fields as $name => $label)
                            <x-ui.field :label="$label">
                                <x-ui.input type="{{ in_array($name, ['email', 'url', 'website'], true) ? ($name === 'email' ? 'email' : 'url') : 'text' }}" name="{{ $name }}" />
                            </x-ui.field>
                        @endforeach
                    </div>
                    <div class="mt-3">
                        <x-ui.button variant="primary">{{ __('Create') }}</x-ui.button>
                    </div>
                </form>
            @endforeach
        </section>
    @endif

    <section class="rounded-lg bg-white dark:bg-gray-900 shadow">
        <h3 class="border-b p-4 font-semibold text-gray-800 dark:text-gray-100">{{ __('Invoice links') }}</h3>
        <p class="px-4 pt-4 text-sm text-gray-500 dark:text-gray-400">{{ __('A link opens one issued invoice and its PDF for anyone who has it, without signing in. Revoke it and it opens nothing.') }}</p>
        <form method="POST" action="{{ route('portal.qr-codes.links.store') }}" class="flex flex-wrap items-end gap-3 p-4">
            @csrf
            <x-ui.field :label="__('Invoice number')">
                <x-ui.input type="text" name="invoice_number" maxlength="100" required value="{{ old('invoice_number') }}" />
            </x-ui.field>
            <x-ui.button variant="primary">{{ __('Create link') }}</x-ui.button>
        </form>
        @if ($errors->getBag('link')->any())
            <p class="px-4 pb-4 text-sm text-red-700 dark:text-red-300">{{ $errors->getBag('link')->first() }}</p>
        @endif
        @foreach ($links as $item)
            <div class="flex flex-wrap items-center gap-3 border-t p-4" data-invoice-link>
                <div class="min-w-0 flex-1">
                    <p class="font-medium text-gray-800 dark:text-gray-100" dir="ltr">{{ $item['link']->invoice?->invoice_number }}</p>
                    <p class="break-all font-mono text-xs text-gray-500 dark:text-gray-400" dir="ltr">{{ $item['url'] }}</p>
                    <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('Opened :count times', ['count' => $item['link']->open_count]) }}</p>
                </div>
                <x-ui.button variant="outline" :href="route('portal.qr-codes.links.download', [$item['link']->id, 'png'])">PNG</x-ui.button>
                <x-ui.button variant="outline" :href="route('portal.qr-codes.links.download', [$item['link']->id, 'svg'])">SVG</x-ui.button>
                <form method="POST" action="{{ route('portal.qr-codes.links.revoke', $item['link']->id) }}">
                    @csrf
                    <x-ui.button variant="danger">{{ __('Revoke') }}</x-ui.button>
                </form>
            </div>
        @endforeach
    </section>

    @if ($admin)
        <form method="POST" action="{{ route('portal.qr-codes.invoices') }}" class="rounded-lg bg-white dark:bg-gray-900 shadow">
            @csrf
            <div class="border-b p-4">
                <h3 class="font-semibold text-gray-800 dark:text-gray-100">{{ __('On invoices') }}</h3>
                <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('One extra QR code per paper, printed apart from the ZATCA QR code and labelled.') }}</p>
            </div>
            <div class="grid grid-cols-1 gap-4 p-4 sm:grid-cols-2">
                @foreach ($papers as $paper)
                    <x-ui.field :label="$paper->label()">
                        <x-ui.select name="papers[{{ $paper->value }}]">
                            <option value="">{{ __('None') }}</option>
                            <option value="{{ \App\Domains\Invoice\Services\InvoiceExtraQr::INVOICE_LINK }}" @selected(($choices[$paper->value] ?? null) === \App\Domains\Invoice\Services\InvoiceExtraQr::INVOICE_LINK)>{{ __('The invoice\'s own link') }}</option>
                            @foreach ($codes as $item)
                                <option value="{{ $item['code']->id }}" @selected(($choices[$paper->value] ?? null) === $item['code']->id)>{{ $item['code']->label }}</option>
                            @endforeach
                        </x-ui.select>
                    </x-ui.field>
                @endforeach
            </div>
            <div class="border-t p-4">
                <x-ui.button variant="primary">{{ __('Save') }}</x-ui.button>
            </div>
        </form>
    @endif
</div>
@endsection
