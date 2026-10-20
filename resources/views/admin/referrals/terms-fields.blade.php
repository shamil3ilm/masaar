{{--
    The referral terms, for the programme or for one partner: on or off,
    each side's reward, the qualifying event, the caps and the expiry.

    @param \App\Domains\Referral\DTOs\ReferralTerms $terms
    @param list<\App\Domains\Referral\Enums\RewardKind> $kinds
    @param list<\App\Domains\Referral\Enums\QualifyingEvent> $events
    @param list<string> $currencies
--}}
<div class="space-y-6">
    <input type="hidden" name="enabled" value="0">
    <x-ui.checkbox name="enabled" value="1" :checked="(bool) old('enabled', $terms->enabled)" description="Off, codes are not offered and none is accepted at sign-up.">The programme runs</x-ui.checkbox>

    @foreach (['referrer' => 'The referrer gets', 'referred' => 'The company referred gets'] as $side => $heading)
        @php($reward = $terms->side($side))
        <fieldset class="space-y-4 rounded-card border border-line p-4">
            <legend class="px-1 text-sm font-semibold text-fg">{{ $heading }}</legend>
            <x-ui.field label="Reward" :error="$side.'_kind'">
                <x-ui.select :name="$side.'_kind'">
                    @foreach ($kinds as $kind)
                        <option value="{{ $kind->value }}" @selected(old($side.'_kind', $reward->kind->value) === $kind->value)>{{ $kind->label() }}</option>
                    @endforeach
                </x-ui.select>
            </x-ui.field>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <x-ui.field label="Percentage off the next invoice" :error="$side.'_percent'" optional>
                    <x-ui.input type="number" :name="$side.'_percent'" min="1" max="100" dir="ltr" value="{{ old($side.'_percent', $reward->percent) }}" />
                </x-ui.field>
                <x-ui.field label="Free months" :error="$side.'_months'" optional>
                    <x-ui.input type="number" :name="$side.'_months'" min="1" max="24" dir="ltr" value="{{ old($side.'_months', $reward->months) }}" />
                </x-ui.field>
                @foreach ($currencies as $currency)
                    <x-ui.field :label="'Fixed credit in '.$currency" :error="$side.'_credit.'.$currency" optional>
                        <x-ui.input type="text" inputmode="decimal" :name="$side.'_credit['.$currency.']'" dir="ltr" value="{{ old($side.'_credit.'.$currency, $reward->credit[$currency] ?? '') }}" />
                    </x-ui.field>
                @endforeach
            </div>
        </fieldset>
    @endforeach

    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <x-ui.field label="Rewarded" error="qualifying_event">
            <x-ui.select name="qualifying_event">
                @foreach ($events as $event)
                    <option value="{{ $event->value }}" @selected(old('qualifying_event', $terms->qualifyingEvent->value) === $event->value)>{{ $event->label() }}</option>
                @endforeach
            </x-ui.select>
        </x-ui.field>
        <x-ui.field label="Rewards per referrer per year" hint="Empty for no limit. Past it, a referrer's reward waits for an admin." error="yearly_cap" optional>
            <x-ui.input type="number" name="yearly_cap" min="1" max="1000" dir="ltr" value="{{ old('yearly_cap', $terms->yearlyCap) }}" />
        </x-ui.field>
        <x-ui.field label="Credit a company may hold, per currency" hint="Empty for no limit. A credit is cut to fit under it." error="credit_cap" optional>
            <x-ui.input type="text" inputmode="decimal" name="credit_cap" dir="ltr" value="{{ old('credit_cap', $terms->creditCap) }}" />
        </x-ui.field>
        <x-ui.field label="Days a credit can be used" hint="Empty: it does not expire." error="expiry_days" optional>
            <x-ui.input type="number" name="expiry_days" min="1" max="3650" dir="ltr" value="{{ old('expiry_days', $terms->expiryDays) }}" />
        </x-ui.field>
    </div>
</div>
