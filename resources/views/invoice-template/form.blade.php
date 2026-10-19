{{--
    The invoice template editor, for the platform's default and for an
    organization's or a branch's own.

    $values     every field as it stands here, inherited ones included
    $own        the fields this level changes itself
    $level      the Level edited
    $template   the template in effect (InvoiceTemplate), for its colour
    $layouts    TemplateLayout cases
    $languages  value => label, as the country allows
    $fields     the country's PrintedField list: printed whatever is chosen
    $locks      template column => the PrintedField that locks it on
    $prints     whether the country hands over a printed copy at all
    $route      route name prefix: admin.invoice-template or portal.invoice-template
    $query      the branch chosen, carried on every link
    $branches, $branch  the organization's branches and the one edited (portal)

    The preview beside the form is the print view itself, drawn by the server
    from the form's values (resources/js/template-preview.js).
--}}
@php
    $old = fn (string $field) => old($field, $values[$field] ?? null);
    $above = match ($level) {
        \App\Domains\Platform\Customization\Level::Platform => __('the shipped template'),
        \App\Domains\Platform\Customization\Level::Organization => __('the platform\'s template'),
        default => __('the organization\'s template'),
    };
    $documents = fn (array $types): string => implode(', ', array_map(fn (string $type): string => $type === 'standard' ? __('tax invoices') : __('simplified tax invoices'), $types));
    $columns = [
        'unit' => [__('Unit'), __('The unit beside each quantity: pieces, hours, kilograms.')],
        'discount' => [__('Discount'), __('A column for each line\'s discount, even where a line has none.')],
        'vat_rate' => [__('VAT rate'), __('The rate each line is taxed at.')],
        'item_code' => [__('Item code'), __('The code of the product a line was made from, as your products list keeps it.')],
    ];
    // A column the country prints whatever is chosen, and why.
    $lockNote = fn (string $key, string $hint): string => ! isset($locks[$key]) ? $hint : ($locks[$key]->always !== []
        ? __(':hint Always printed on :documents, whatever is chosen here (:source).', ['hint' => $hint, 'documents' => $documents($locks[$key]->always), 'source' => $locks[$key]->source])
        : __(':hint Always printed when a line has one, whatever is chosen here (:source).', ['hint' => $hint, 'source' => $locks[$key]->source]));
    $text = [
        'header' => [__('Header'), __('A line or two under your logo: a branch\'s address, opening hours.')],
        'footer' => [__('Footer'), __('At the foot of every invoice: a thank-you, a website.')],
        'terms' => [__('Terms and conditions'), __('Payment terms, returns, warranty.')],
    ];
@endphp

@unless ($prints)
    <x-ui.alert tone="info" class="mb-6 max-w-3xl">
        {{ __('Invoices in your country reach the buyer as e-invoices, so no printed copy is handed over and this template is not used yet. It is kept for when one is.') }}
    </x-ui.alert>
@endunless

<p class="mb-6 max-w-3xl text-sm text-muted">
    {{ $own === [] ? __('Everything here follows :above.', ['above' => $above]) : __('Settings made here: :count. The rest follow :above.', ['count' => count($own), 'above' => $above]) }}
</p>

<div class="grid items-start gap-6 xl:grid-cols-2">
    <div class="space-y-6">
        <form method="POST" action="{{ route($route.'.update') }}" class="space-y-6" data-template-form>
            @csrf
            @if (! empty($branch))
                <input type="hidden" name="branch" value="{{ $branch }}">
            @endif

            <x-ui.section :title="__('Layout')" :description="__('How the page is arranged. A receipt roll always prints the receipt.')">
                <div class="grid gap-3 sm:grid-cols-2">
                    @foreach ($layouts as $layout)
                        <x-ui.radio name="layout" value="{{ $layout->value }}" :checked="$old('layout') === $layout->value" tile :description="$layout->description()">{{ $layout->label() }}</x-ui.radio>
                    @endforeach
                </div>
                <div class="mt-4 grid gap-4 sm:grid-cols-2">
                    <x-ui.field :label="__('Logo position')" error="logo_position">
                        <x-ui.select name="logo_position">
                            @foreach (['start' => __('Leading side'), 'left' => __('Left'), 'center' => __('Centre'), 'right' => __('Right')] as $value => $label)
                                <option value="{{ $value }}" @selected($old('logo_position') === $value)>{{ $label }}</option>
                            @endforeach
                        </x-ui.select>
                    </x-ui.field>
                    <x-ui.field :label="__('Logo size')" error="logo_size">
                        <x-ui.select name="logo_size">
                            @foreach (['small' => __('Small'), 'medium' => __('Medium'), 'large' => __('Large')] as $value => $label)
                                <option value="{{ $value }}" @selected($old('logo_size') === $value)>{{ $label }}</option>
                            @endforeach
                        </x-ui.select>
                    </x-ui.field>
                </div>
            </x-ui.section>

            <x-ui.section :title="__('Colour and language')">
                <div class="flex flex-wrap items-center gap-4">
                    <input type="hidden" name="accent_custom" value="0">
                    <x-ui.checkbox name="accent_custom" value="1" :checked="$old('accent') !== null">{{ __('Use a colour of its own') }}</x-ui.checkbox>
                    <x-ui.color-input name="accent_color" value="{{ $old('accent') ?? $template->accent }}" :aria-label="__('Invoice colour')" />
                </div>
                <p class="mt-1 text-xs text-muted">{{ __('Unticked, your theme\'s colour is used, darkened where needed. Any colour must read on white and print as a dark grey in black and white: its contrast with white is at least 4.5 to 1. A receipt is always black and white.') }}</p>
                @error('accent')<p class="mt-1 text-xs font-medium text-danger">{{ $message }}</p>@enderror

                <x-ui.field :label="__('Language')" error="language" class="mt-4">
                    <x-ui.select name="language">
                        <option value="" @selected($old('language') === null)>{{ __('As your printing settings say') }}</option>
                        @foreach ($languages as $value => $label)
                            <option value="{{ $value }}" @selected($old('language') === $value)>{{ $label }}</option>
                        @endforeach
                    </x-ui.select>
                </x-ui.field>
            </x-ui.section>

            <x-ui.section :title="__('Your text')" :description="__('Each in English and Arabic; the invoice prints the languages it is in.')" collapse>
                <div class="space-y-4">
                    @foreach ($text as $key => [$label, $hint])
                        <div class="grid gap-4 sm:grid-cols-2">
                            @foreach (['en' => __(':label in English', ['label' => $label]), 'ar' => __(':label in Arabic', ['label' => $label])] as $language => $caption)
                                <x-ui.field :label="$caption" :hint="$language === 'en' ? $hint : null" :error="$key.'_'.$language" optional>
                                    @if ($key === 'terms')
                                        <x-ui.textarea name="{{ $key }}_{{ $language }}" rows="3" maxlength="2000" dir="{{ $language === 'ar' ? 'rtl' : 'ltr' }}">{{ $old($key.'_'.$language) }}</x-ui.textarea>
                                    @else
                                        <x-ui.input name="{{ $key }}_{{ $language }}" value="{{ $old($key.'_'.$language) }}" maxlength="300" dir="{{ $language === 'ar' ? 'rtl' : 'ltr' }}" />
                                    @endif
                                </x-ui.field>
                            @endforeach
                        </div>
                    @endforeach
                </div>
            </x-ui.section>

            <x-ui.section :title="__('Bank details')" :description="__('The account a buyer pays into, printed with the terms.')" collapse>
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-ui.field :label="__('Bank')" error="bank_name" optional>
                        <x-ui.input name="bank_name" value="{{ $old('bank_name') }}" maxlength="100" />
                    </x-ui.field>
                    <x-ui.field :label="__('Account name')" error="bank_account_name" optional>
                        <x-ui.input name="bank_account_name" value="{{ $old('bank_account_name') }}" maxlength="140" />
                    </x-ui.field>
                    <x-ui.field :label="__('IBAN')" :hint="__('Checked as banks check it, so a mistyped digit is caught here.')" error="bank_iban" optional>
                        <x-ui.input name="bank_iban" value="{{ $old('bank_iban') }}" maxlength="50" dir="ltr" autocomplete="off" class="font-mono uppercase" />
                    </x-ui.field>
                    <x-ui.field :label="__('SWIFT code')" error="bank_swift" optional>
                        <x-ui.input name="bank_swift" value="{{ $old('bank_swift') }}" maxlength="11" dir="ltr" autocomplete="off" class="font-mono uppercase" />
                    </x-ui.field>
                </div>
            </x-ui.section>

            <x-ui.section :title="__('Columns and QR codes')">
                <div class="space-y-1">
                    @foreach ($columns as $key => [$label, $hint])
                        <input type="hidden" name="column_{{ $key }}" value="0">
                        <x-ui.checkbox name="column_{{ $key }}" value="1" :checked="(bool) $old('column_'.$key)"
                                       :description="$lockNote($key, $hint)">{{ $label }}</x-ui.checkbox>
                    @endforeach
                </div>
                <div class="mt-4">
                    <x-ui.switch name="business_qr" value="1" off="0" :checked="(bool) $old('business_qr')" :description="__('The QR code you chose for invoices under QR codes, beside the country\'s. The country\'s own QR code is always printed.')">{{ __('Print your business QR code') }}</x-ui.switch>
                </div>
            </x-ui.section>

            @if ($fields !== [])
                <x-ui.section :title="__('Fields the law requires')" :description="__('Printed on every invoice in every layout, whatever is chosen above. A layout can move and restyle them, never leave them out.')" collapse>
                    <ul class="divide-y divide-line text-sm">
                        @foreach ($fields as $field)
                            <li class="flex items-start gap-3 py-2" data-locked-field="{{ $field->key }}">
                                <x-ui.icon name="shield" class="mt-0.5 size-4 text-subtle" />
                                <span class="min-w-0">
                                    <span class="block font-medium text-fg">{{ app()->getLocale() === 'ar' ? $field->label->ar : $field->label->en }}</span>
                                    <span class="block text-xs text-muted">
                                        @if ($field->always !== [])
                                            {{ __('Always, on :documents.', ['documents' => $documents($field->always)]) }}
                                        @endif
                                        @if (array_diff($field->whenGiven, $field->always) !== [])
                                            {{ __('When the invoice has one, on :documents.', ['documents' => $documents(array_values(array_diff($field->whenGiven, $field->always)))]) }}
                                        @endif
                                        <span dir="ltr">{{ $field->source }}</span>
                                    </span>
                                </span>
                            </li>
                        @endforeach
                    </ul>
                </x-ui.section>
            @endif

            <div class="flex flex-wrap items-center gap-3">
                <x-ui.button>{{ __('Save invoice template') }}</x-ui.button>
            </div>
        </form>

        @if ($own !== [])
            <form method="POST" action="{{ route($route.'.reset') }}"
                  data-confirm-tone="danger" data-confirm-action="{{ __('Reset') }}"
                  data-confirm="{{ __('Reset the invoice template?') }}"
                  data-confirm-detail="{{ __('Every setting made here goes back to :above.', ['above' => $above]) }}">
                @csrf
                @if (! empty($branch))
                    <input type="hidden" name="branch" value="{{ $branch }}">
                @endif
                <x-ui.button variant="ghost">{{ __('Reset to :above', ['above' => $above]) }}</x-ui.button>
            </form>
        @endif
    </div>

    @if ($prints)
        <x-ui.card :title="__('Preview')" :description="__('A made-up invoice with these settings, saved or not.')" class="xl:sticky xl:top-6">
            <x-slot:actions>
                <x-ui.button variant="secondary" size="sm" :href="route($route.'.sample', [...$query, 'paper' => 'a4'])" data-template-link="{{ route($route.'.sample') }}">{{ __('Download sample PDF') }}</x-ui.button>
                <x-ui.button variant="ghost" size="sm" :href="route($route.'.preview', [...$query, 'paper' => 'a4'])" target="_blank" data-template-link="{{ route($route.'.preview') }}">{{ __('Open preview') }}</x-ui.button>
            </x-slot:actions>
            <x-ui.field :label="__('Paper')" class="mb-4 max-w-xs">
                <x-ui.select data-template-paper>
                    @foreach (\App\Domains\Invoice\Enums\PaperSize::cases() as $size)
                        <option value="{{ $size->value }}">{{ $size->label() }}</option>
                    @endforeach
                </x-ui.select>
            </x-ui.field>
            <iframe data-template-preview data-preview-url="{{ route($route.'.preview') }}" sandbox="allow-same-origin" title="{{ __('Invoice preview') }}"
                    class="h-[48rem] w-full rounded-tile border border-line"></iframe>
        </x-ui.card>
    @endif
</div>
