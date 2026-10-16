{{--
    The seven emirates, as PINT AE names them (IBR-128-AE): the code is what
    the document carries, the name is what a person reads.

    Written out rather than looped from a map so each name is a literal the
    translation check can see.

    @param string|null $selected  the code currently chosen
--}}
<option value="AUH" @selected($selected === 'AUH')>{{ __('Abu Dhabi') }}</option>
<option value="DXB" @selected($selected === 'DXB')>{{ __('Dubai') }}</option>
<option value="SHJ" @selected($selected === 'SHJ')>{{ __('Sharjah') }}</option>
<option value="AJM" @selected($selected === 'AJM')>{{ __('Ajman') }}</option>
<option value="UAQ" @selected($selected === 'UAQ')>{{ __('Umm Al Quwain') }}</option>
<option value="RAK" @selected($selected === 'RAK')>{{ __('Ras Al Khaimah') }}</option>
<option value="FUJ" @selected($selected === 'FUJ')>{{ __('Fujairah') }}</option>
