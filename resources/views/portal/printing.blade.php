@extends('layouts.portal')

@section('title', __('Printing'))

@section('content')
<x-ui.page-header :title="__('Printing')"
                  :description="__('The paper an invoice prints on. Your own choice comes first, then the branch that issued the invoice, then the organization. The print screen can switch paper for one invoice.')" />

<div class="max-w-2xl space-y-6">
    <form method="POST" action="{{ route('portal.printing.mine') }}" class="rounded-card border border-line bg-surface shadow-card">
        @csrf
        <div class="border-b border-line px-5 py-4">
            <h2 class="text-base font-semibold text-fg">{{ __('Your paper') }}</h2>
            <p class="mt-0.5 text-sm text-subtle">{{ __('For the printer where you work, such as a till with a receipt roll.') }}</p>
        </div>
        <div class="p-5">
            <x-ui.field :label="__('Paper')">
                <x-ui.select name="paper">
                    <option value="" @selected($mine === null)>{{ __('As the branch or organization') }}</option>
                    @foreach ($papers as $paper)
                        <option value="{{ $paper->value }}" @selected($mine === $paper)>{{ $paper->label() }}</option>
                    @endforeach
                </x-ui.select>
            </x-ui.field>
        </div>
        <div class="border-t border-line px-5 py-4">
            <x-ui.button variant="primary">{{ __('Save') }}</x-ui.button>
        </div>
    </form>

    @if ($admin)
        <form method="POST" action="{{ route('portal.printing.update') }}" class="rounded-card border border-line bg-surface shadow-card">
            @csrf
            <div class="border-b border-line px-5 py-4">
                <h2 class="text-base font-semibold text-fg">{{ __('Organization and branches') }}</h2>
                <p class="mt-0.5 text-sm text-subtle">{{ __('A branch with a receipt printer at its till can print on its roll while the office prints on A4.') }}</p>
            </div>
            <div class="grid grid-cols-1 gap-4 p-5 sm:grid-cols-2">
                <x-ui.field :label="__('Organization')">
                    <x-ui.select name="paper">
                        @foreach ($papers as $paper)
                            <option value="{{ $paper->value }}" @selected($organizationPaper === $paper)>{{ $paper->label() }}</option>
                        @endforeach
                    </x-ui.select>
                </x-ui.field>

            </div>

            @foreach ($branches as $branch)
                <fieldset class="grid grid-cols-1 gap-4 border-t border-line p-5 sm:grid-cols-3">
                    <legend class="sr-only">{{ $branch['name'] }}</legend>
                    <x-ui.field :label="$branch['name']">
                        <x-ui.select name="branches[{{ $branch['id'] }}]">
                            <option value="" @selected($branch['paper'] === null)>{{ __('As the organization') }}</option>
                            @foreach ($papers as $paper)
                                <option value="{{ $paper->value }}" @selected($branch['paper'] === $paper)>{{ $paper->label() }}</option>
                            @endforeach
                        </x-ui.select>
                    </x-ui.field>
                    {{-- For the printer image and ESC/POS: most receipt
                         printers are 203 dpi, 576 dots across on 80 mm and
                         384 on 58 mm. --}}
                    <x-ui.field :label="__('Receipt printer dpi')">
                        <x-ui.input type="number" name="printers[{{ $branch['id'] }}][dpi]" min="100" max="600" placeholder="203" value="{{ $branch['dpi'] }}" />
                    </x-ui.field>
                    <x-ui.field :label="__('Dots across the head')">
                        <x-ui.input type="number" name="printers[{{ $branch['id'] }}][dots]" min="64" max="1280" step="8" placeholder="576" value="{{ $branch['dots'] }}" />
                    </x-ui.field>
                </fieldset>
            @endforeach
            <div class="border-t border-line px-5 py-4">
                <x-ui.button variant="primary">{{ __('Save') }}</x-ui.button>
            </div>
        </form>
    @endif
</div>
@endsection
