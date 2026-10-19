@extends('layouts.admin')

@section('title', __('Business types'))

@section('content')
<x-ui.page-header :title="__('Business types')"
                  :description="__('What an organization can say it is, at sign-up and on its company page. Each type sets defaults only - the invoice type, prices with VAT, the paper, the shortcuts and setup steps - and never refuses a feature. Each country offers the types it serves.')">
    <x-slot:breadcrumb>
        <x-ui.breadcrumb :items="[['label' => __('Settings'), 'href' => route('admin.settings')], ['label' => __('Business types')]]" />
    </x-slot:breadcrumb>
</x-ui.page-header>

<div class="max-w-3xl space-y-4">
    @foreach ($types as $type)
        <x-ui.section :title="$type->label.' · '.$type->labelAr" :description="$type->retired ? __('Retired: not offered, kept by the organizations that chose it.') : $type->key" closed>
            @admincan('platform.configure')
                <div class="mb-4 flex flex-wrap gap-2">
                    @foreach (['up' => __('Move up'), 'down' => __('Move down')] as $direction => $label)
                        <form method="POST" action="{{ route('admin.business-types.move', $type->key) }}">
                            @csrf
                            <input type="hidden" name="direction" value="{{ $direction }}">
                            <x-ui.button size="sm" variant="ghost" :disabled="($direction === 'up' && $loop->parent->first) || ($direction === 'down' && $loop->parent->last)">{{ $label }}</x-ui.button>
                        </form>
                    @endforeach
                    <form method="POST" action="{{ route('admin.business-types.retire', $type->key) }}">
                        @csrf
                        <input type="hidden" name="retired" value="{{ $type->retired ? '0' : '1' }}">
                        <x-ui.button size="sm" variant="secondary">{{ $type->retired ? __('Offer again') : __('Retire') }}</x-ui.button>
                    </form>
                </div>

                <form method="POST" action="{{ route('admin.business-types.update', $type->key) }}">
                    @csrf
                    @include('admin.partials.business-type-fields', ['type' => $type])
                    <x-ui.button class="mt-4">{{ __('Save business type') }}</x-ui.button>
                </form>
            @else
                @include('admin.partials.business-type-fields', ['type' => $type])
            @endadmincan
        </x-ui.section>
    @endforeach

    @admincan('platform.configure')
        <x-ui.section :title="__('Add a business type')">
            <form method="POST" action="{{ route('admin.business-types.store') }}">
                @csrf
                <x-ui.field :label="__('Key')" :hint="__('Lower-case letters, digits and underscores; it cannot change once added.')" error="key" class="mb-4">
                    <x-ui.input type="text" name="key" maxlength="40" required dir="ltr" value="{{ old('key') }}" />
                </x-ui.field>
                @include('admin.partials.business-type-fields', ['type' => null])
                <x-ui.button class="mt-4">{{ __('Add business type') }}</x-ui.button>
            </form>
        </x-ui.section>
    @endadmincan
</div>
@endsection
