{{--
    The reason a request for approval carries, on a form whose action an
    approval policy holds back - shown only while its policy is on, since the
    reason goes nowhere otherwise. HoldsForApproval reads it.

    @param string $action   the approval action key, e.g. admins.grant
    @param bool   $compact  one line, for a form inside a table row
--}}
@inject('approvalPolicies', \App\Domains\Platform\Approvals\ApprovalPolicies::class)
@if ($approvalPolicies->get($action)->enabled)
    <x-ui.field :label="__('Why (this waits for approval)')" error="approval_reason">
        @if ($compact ?? false)
            <x-ui.input type="text" name="approval_reason" maxlength="2000" />
        @else
            <x-ui.textarea name="approval_reason" rows="2" maxlength="2000">{{ old('approval_reason') }}</x-ui.textarea>
        @endif
    </x-ui.field>
@endif
