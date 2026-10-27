{{--
    A supplier's details as a form, to add one or change one.

    @param \App\Domains\Books\Models\Supplier $supplier
    @param string $action
    @param bool $readOnly
--}}
<form method="POST" action="{{ $action }}" class="space-y-4">
    @csrf
    <fieldset @disabled($readOnly ?? false) class="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <x-ui.field :label="__('Name')" error="name">
            <x-ui.input name="name" maxlength="200" required value="{{ old('name', $supplier->name) }}" />
        </x-ui.field>
        <x-ui.field :label="__('Name in Arabic')" error="name_ar" optional>
            <x-ui.input name="name_ar" maxlength="200" dir="rtl" lang="ar" value="{{ old('name_ar', $supplier->name_ar) }}" />
        </x-ui.field>
        <x-ui.field :label="__('VAT number')" error="vat_number" optional>
            <x-ui.input name="vat_number" maxlength="50" dir="ltr" value="{{ old('vat_number', $supplier->vat_number) }}" />
        </x-ui.field>
        <x-ui.field :label="__('Country')" error="country_code" :hint="__('Where the supplier is. Its services from abroad are reverse charge.')" optional>
            <x-ui.country-select name="country_code" :value="old('country_code', $supplier->country_code)" :empty="__('Not set')" />
        </x-ui.field>
        <x-ui.field :label="__('City')" error="city" optional>
            <x-ui.input name="city" maxlength="100" value="{{ old('city', $supplier->city) }}" />
        </x-ui.field>
        <x-ui.field :label="__('Emirate or region')" error="subdivision" optional>
            <x-ui.input name="subdivision" maxlength="50" value="{{ old('subdivision', $supplier->subdivision) }}" />
        </x-ui.field>
        <x-ui.field :label="__('Email')" error="email" optional>
            <x-ui.input type="email" name="email" maxlength="190" value="{{ old('email', $supplier->email) }}" />
        </x-ui.field>
        <x-ui.field :label="__('Phone')" error="phone" optional>
            <x-ui.input name="phone" maxlength="50" value="{{ old('phone', $supplier->phone) }}" />
        </x-ui.field>
    </fieldset>
    @unless ($readOnly ?? false)
        <x-ui.button>{{ __('Save supplier') }}</x-ui.button>
    @endunless
</form>
