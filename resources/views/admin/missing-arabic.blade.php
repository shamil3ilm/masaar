@extends('layouts.admin')

@section('title', 'Missing Arabic names - '.$organization->name)

{{--
    The Arabic names one organization still lacks where its country requires
    them (ArabicNameReport): its own, and its customers' and products' that
    invoices take theirs from. Each is held to the country's own rule for it,
    so this is exactly what an invoice would be refused for. Archived records
    are left out. The organization fills them in on its company, customer and
    product pages.
--}}
@section('content')
<div class="mb-6">
    <div class="flex items-center space-x-2 text-sm text-muted mb-2">
        <a href="{{ route('admin.organizations') }}" class="hover:text-accent-strong">Organizations</a>
        <span>/</span>
        <a href="{{ route('admin.organization.detail', $organization->id) }}" class="hover:text-accent-strong">{{ $organization->name }}</a>
        <span>/</span>
        <span>Missing Arabic names</span>
    </div>
    <h1 class="text-2xl font-semibold tracking-tight text-fg">Missing Arabic names</h1>
    <p class="mt-1 max-w-3xl text-sm text-muted">
        Fill these in before enforcement reaches this organization: an invoice naming one of these customers or products takes its Arabic name, and one without is refused.
    </p>
</div>

<x-ui.section title="The organization" collapse class="mb-6">
    <p class="text-sm {{ $seller ? 'text-warning' : 'text-muted' }}">
        {{ $seller ? 'Its own Arabic name is missing: it is printed on every invoice and in its QR code.' : 'Its own Arabic name is given.' }}
    </p>
</x-ui.section>

<x-ui.section :title="'Customers ('.count($customers).')'" collapse flush class="mb-6">
    <x-ui.table :primary="[0, 1]">
        <x-slot:head>
            <x-ui.th>Name</x-ui.th>
            <x-ui.th>VAT number</x-ui.th>
            <x-ui.th>Arabic name now</x-ui.th>
        </x-slot:head>

        @forelse ($customers as $customer)
        <tr class="hover:bg-sunken">
            <td class="px-6 py-4 text-sm font-medium text-fg">{{ $customer->name }}</td>
            <td class="px-6 py-4 text-sm text-muted font-mono">{{ $customer->vat_number ?? '-' }}</td>
            <td class="px-6 py-4 text-sm text-muted" dir="auto">{{ $customer->name_ar ?: '-' }}</td>
        </tr>
        @empty
        <x-ui.empty :columns="3">No customer is missing its Arabic name</x-ui.empty>
        @endforelse
    </x-ui.table>
</x-ui.section>

<x-ui.section :title="'Products ('.count($products).')'" collapse flush>
    <x-ui.table :primary="[0, 1]">
        <x-slot:head>
            <x-ui.th>Name</x-ui.th>
            <x-ui.th>Code or SKU</x-ui.th>
            <x-ui.th>Arabic name now</x-ui.th>
        </x-slot:head>

        @forelse ($products as $product)
        <tr class="hover:bg-sunken">
            <td class="px-6 py-4 text-sm font-medium text-fg">{{ $product->name }}</td>
            <td class="px-6 py-4 text-sm text-muted font-mono">{{ $product->sku ?? '-' }}</td>
            <td class="px-6 py-4 text-sm text-muted" dir="auto">{{ $product->name_ar ?: '-' }}</td>
        </tr>
        @empty
        <x-ui.empty :columns="3">No product is missing its Arabic name</x-ui.empty>
        @endforelse
    </x-ui.table>
</x-ui.section>
@endsection
