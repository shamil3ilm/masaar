{{--
    A custom admin role's fields: its name, what it is for, its rank and its
    permissions. The permissions are code's; a role picks from them.

    @param array|null $role  the role being changed, or null for a new one
--}}
@php
    $chosen = array_map(fn ($permission) => $permission->value, $role['permissions'] ?? []);
@endphp
<div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
    <div class="sm:col-span-2">
        <x-ui.field :label="__('Name')" error="name">
            <x-ui.input type="text" name="name" maxlength="100" required value="{{ $role['name'] ?? old('name') }}" />
        </x-ui.field>
    </div>
    <x-ui.field :label="__('Rank')" :hint="__('1 to :max. Only the owner is above.', ['max' => $maxRank])" error="rank">
        <x-ui.select name="rank">
            @for ($rank = $maxRank; $rank >= 1; $rank--)
                <option value="{{ $rank }}" @selected((int) ($role['rank'] ?? old('rank', 1)) === $rank)>{{ $rank }}</option>
            @endfor
        </x-ui.select>
    </x-ui.field>
</div>
<x-ui.field :label="__('What it is for')" optional error="description">
    <x-ui.input type="text" name="description" maxlength="500" value="{{ $role['description'] ?? old('description') }}" />
</x-ui.field>
<fieldset>
    <legend class="text-sm font-medium text-fg">{{ __('Permissions') }}</legend>
    @error('permissions')
        <p class="mt-1 text-xs font-medium text-danger">{{ $message }}</p>
    @enderror
    <div class="mt-1 grid grid-cols-1 gap-x-4 sm:grid-cols-2">
        @foreach ($permissions as $permission)
            <x-ui.checkbox name="permissions[]" value="{{ $permission->value }}" :checked="in_array($permission->value, $chosen, true)">
                <span dir="ltr">{{ $permission->value }}</span>
            </x-ui.checkbox>
        @endforeach
    </div>
</fieldset>
