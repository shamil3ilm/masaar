{{--
    The legal registration types PINT AE takes (BTAE-15, BTAE-16).

    @param string|null $selected  the code currently chosen
--}}
<option value="TL" @selected($selected === 'TL')>{{ __('Trade licence') }}</option>
<option value="EID" @selected($selected === 'EID')>{{ __('Emirates ID') }}</option>
<option value="PAS" @selected($selected === 'PAS')>{{ __('Passport') }}</option>
<option value="CD" @selected($selected === 'CD')>{{ __('Cabinet decision') }}</option>
