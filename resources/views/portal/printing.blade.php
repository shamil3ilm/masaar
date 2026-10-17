@extends('layouts.portal')

@section('title', __('Printing'))

@section('content')
<div class="mb-6">
    <h2 class="text-2xl font-bold text-gray-800 dark:text-gray-100">{{ __('Printing') }}</h2>
    <p class="text-gray-500 dark:text-gray-400">
        {{ __('The paper an invoice prints on. Your own choice comes first, then the branch that issued the invoice, then the organization. The print screen can switch paper for one invoice.') }}
    </p>
</div>

<div class="max-w-2xl space-y-6">
    <form method="POST" action="{{ route('portal.printing.mine') }}" class="rounded-lg bg-white dark:bg-gray-900 shadow">
        @csrf
        <div class="border-b p-4">
            <h3 class="font-semibold text-gray-800 dark:text-gray-100">{{ __('Your paper') }}</h3>
            <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('For the printer where you work, such as a till with a receipt roll.') }}</p>
        </div>
        <div class="p-4">
            <x-ui.field :label="__('Paper')">
                <x-ui.select name="paper">
                    <option value="" @selected($mine === null)>{{ __('As the branch or organization') }}</option>
                    @foreach ($papers as $paper)
                        <option value="{{ $paper->value }}" @selected($mine === $paper)>{{ $paper->label() }}</option>
                    @endforeach
                </x-ui.select>
            </x-ui.field>
        </div>
        <div class="border-t p-4">
            <x-ui.button variant="primary">{{ __('Save') }}</x-ui.button>
        </div>
    </form>

    @if ($admin)
        <form method="POST" action="{{ route('portal.printing.update') }}" class="rounded-lg bg-white dark:bg-gray-900 shadow">
            @csrf
            <div class="border-b p-4">
                <h3 class="font-semibold text-gray-800 dark:text-gray-100">{{ __('Organization and branches') }}</h3>
                <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('A branch with a receipt printer at its till can print on its roll while the office prints on A4.') }}</p>
            </div>
            <div class="grid grid-cols-1 gap-4 p-4 sm:grid-cols-2">
                <x-ui.field :label="__('Organization')">
                    <x-ui.select name="paper">
                        @foreach ($papers as $paper)
                            <option value="{{ $paper->value }}" @selected($organizationPaper === $paper)>{{ $paper->label() }}</option>
                        @endforeach
                    </x-ui.select>
                </x-ui.field>

                @foreach ($branches as $branch)
                    <x-ui.field :label="$branch['name']">
                        <x-ui.select name="branches[{{ $branch['id'] }}]">
                            <option value="" @selected($branch['paper'] === null)>{{ __('As the organization') }}</option>
                            @foreach ($papers as $paper)
                                <option value="{{ $paper->value }}" @selected($branch['paper'] === $paper)>{{ $paper->label() }}</option>
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
