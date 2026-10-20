{{--
    A feature request category's names, and whether it names the other
    system, for adding one or changing one.

    @param \App\Domains\Support\DTOs\RequestCategory|null $category
--}}
<div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
    <x-ui.field label="Name in English" error="label">
        <x-ui.input type="text" name="label" maxlength="80" required value="{{ $category?->label }}" />
    </x-ui.field>
    <x-ui.field label="Name in Arabic" error="label_ar">
        <x-ui.input type="text" name="label_ar" maxlength="80" required dir="rtl" lang="ar" value="{{ $category?->labelAr }}" />
    </x-ui.field>
</div>
<input type="hidden" name="names_system" value="0">
<x-ui.checkbox name="names_system" value="1" :checked="(bool) $category?->namesSystem" description="An integration: the customer is asked which ERP, store or bank.">Asks which other system</x-ui.checkbox>
