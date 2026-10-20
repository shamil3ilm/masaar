{{--
    A business type's names and defaults, for adding one or changing one.
    Every default can be left at "No default": the organization's own
    settings, or the platform's, then decide.

    @param \App\Domains\Organization\DTOs\BusinessType|null $type
    @param array<string, string> $papers
    @param array<string, string> $panels  key => route
    @param list<string> $steps
--}}
@php
    $panelLabels = ['new_invoice' => __('New invoice'), 'products' => __('Products'), 'customers' => __('Customers'), 'reports' => __('Reports'), 'daily_close' => __('Daily close'), 'printing' => __('Printing')];
    $stepLabels = ['products' => __('Add products, with their VAT treatment'), 'customers' => __('Add regular customers'), 'printing' => __('Choose the paper')];
    $prices = $type?->pricesIncludeVat === null ? '' : ($type->pricesIncludeVat ? '1' : '0');
@endphp
<div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
    <x-ui.field :label="__('Name in English')">
        <x-ui.input type="text" name="label" maxlength="80" required value="{{ $type?->label }}" />
    </x-ui.field>
    <x-ui.field :label="__('Name in Arabic')">
        <x-ui.input type="text" name="label_ar" maxlength="80" required dir="rtl" lang="ar" value="{{ $type?->labelAr }}" />
    </x-ui.field>
    <x-ui.field :label="__('Invoice type')">
        <x-ui.select name="invoice_type">
            <option value="">{{ __('No default') }}</option>
            <option value="simplified" @selected($type?->invoiceType === 'simplified')>{{ __('Simplified (B2C)') }}</option>
            <option value="standard" @selected($type?->invoiceType === 'standard')>{{ __('Standard (B2B)') }}</option>
        </x-ui.select>
    </x-ui.field>
    <x-ui.field :label="__('Prices')">
        <x-ui.select name="prices_include_vat">
            <option value="">{{ __('No default') }}</option>
            <option value="1" @selected($prices === '1')>{{ __('Include VAT - the customer pays exactly these') }}</option>
            <option value="0" @selected($prices === '0')>{{ __('Exclude VAT - VAT is added to them') }}</option>
        </x-ui.select>
    </x-ui.field>
    <x-ui.field :label="__('Payment means code')" :hint="__('A UNTDID 4461 code, such as 10 cash or 30 credit transfer. A country that does not have it starts on its own default.')">
        <x-ui.input type="text" name="payment_means" maxlength="3" dir="ltr" value="{{ $type?->paymentMeans }}" />
    </x-ui.field>
    <x-ui.field :label="__('Buyer fields')">
        <x-ui.select name="buyer_fields">
            <option value="full" @selected(($type?->buyerFields ?? 'full') === 'full')>{{ __('All shown') }}</option>
            <option value="minimal" @selected($type?->buyerFields === 'minimal')>{{ __('Name only, the rest folded away') }}</option>
        </x-ui.select>
    </x-ui.field>
    <x-ui.field :label="__('Paper')">
        <x-ui.select name="paper">
            <option value="">{{ __('No default') }}</option>
            @foreach ($papers as $value => $label)
                <option value="{{ $value }}" @selected($type?->paper === $value)>{{ $label }}</option>
            @endforeach
        </x-ui.select>
    </x-ui.field>
    <x-ui.field :label="__('Schedules bill')">
        <x-ui.select name="schedule_frequency">
            <option value="">{{ __('No default') }}</option>
            @foreach (\App\Domains\Invoice\Enums\ScheduleFrequency::options() as $value => $label)
                <option value="{{ $value }}" @selected($type?->scheduleFrequency === $value)>{{ $label }}</option>
            @endforeach
        </x-ui.select>
    </x-ui.field>
    <x-ui.field :label="__('VAT treatments offered first')" :hint="__('Exemption codes separated by commas, such as VATEX-SA-35. Each country offers those it has.')">
        <x-ui.input type="text" name="vat_hints" maxlength="500" dir="ltr" value="{{ implode(', ', $type?->vatHints ?? []) }}" />
    </x-ui.field>
    {{-- A pharmacy or a clinic bills the patient and their insurer: its
         invoice form opens on splitting the sale. Any business can split. --}}
    <x-ui.checkbox name="insurer_split" value="1" :checked="(bool) ($type?->insurerSplit ?? false)"
                   :description="__('The invoice form opens on splitting a sale between a patient and their insurer. Any business can still split one.')">{{ __('Split with insurer') }}</x-ui.checkbox>
</div>

<div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
    <fieldset>
        <legend class="text-sm font-medium text-fg">{{ __('Dashboard shortcuts') }}</legend>
        @foreach (array_keys($panels) as $panel)
            <x-ui.checkbox name="dashboard_panels[]" value="{{ $panel }}" :checked="in_array($panel, $type?->dashboardPanels ?? [], true)">{{ $panelLabels[$panel] ?? $panel }}</x-ui.checkbox>
        @endforeach
    </fieldset>
    <fieldset>
        <legend class="text-sm font-medium text-fg">{{ __('Setup steps') }}</legend>
        @foreach ($steps as $step)
            <x-ui.checkbox name="setup_steps[]" value="{{ $step }}" :checked="in_array($step, $type?->setupSteps ?? [], true)">{{ $stepLabels[$step] ?? $step }}</x-ui.checkbox>
        @endforeach
    </fieldset>
</div>
