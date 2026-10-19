{{--
    The events an endpoint is sent, as boxes to tick. $chosen holds the ones
    already chosen; "*" - every event - is kept when an endpoint was made
    with it over the API.
--}}
<fieldset>
    <legend class="text-sm font-medium text-fg">{{ __('Events') }}</legend>
    <p class="text-xs text-subtle">{{ __('Each delivery is a POST of the event as JSON, signed with the endpoint\'s secret in X-Webhook-Signature.') }}</p>
    <div class="mt-2 grid grid-cols-1 gap-x-6 sm:grid-cols-2">
        @if (in_array('*', $chosen, true))
            <x-ui.checkbox name="events[]" value="*" checked :description="'*'">{{ __('Every event') }}</x-ui.checkbox>
        @endif
        @foreach ($events as $event => $label)
            <x-ui.checkbox name="events[]" value="{{ $event }}" :checked="in_array($event, $chosen, true)" :description="$event">{{ $label }}</x-ui.checkbox>
        @endforeach
    </div>
    @error('events')
        <p class="mt-1 text-sm text-danger">{{ $message }}</p>
    @enderror
</fieldset>
