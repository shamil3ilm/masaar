{{--
    The reason to renew while documents are unfiled (RenewalGuard), on the
    renewal's forms - only when some are, since otherwise it goes nowhere.

    @param Collection $waiting    RenewalGuard::waiting
    @param int        $minReason  RenewalGuard::MIN_REASON
--}}
@if ($waiting->isNotEmpty())
    <x-ui.field :label="__('Why renew now')" error="renewal_override_reason"
                :hint="__('At least :min characters. Recorded with the documents left unfiled.', ['min' => $minReason])">
        <x-ui.textarea name="renewal_override_reason" rows="2" maxlength="500" required minlength="{{ $minReason }}">{{ old('renewal_override_reason') }}</x-ui.textarea>
    </x-ui.field>
@endif
