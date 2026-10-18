# Approvals (maker-checker)

An admin action its policy holds back does not happen when asked. It becomes a
request carrying the requester's reason, what it would change (before and
after, read when asked) and an expiry. It runs once enough approvers have
approved it, exactly as requested, through the same code the direct action
runs. It ends when an approver rejects it with a reason, when the requester
withdraws it, or when it lapses.

## Rules

- Nobody approves their own request, or a change to their own access.
- An approver needs `approvals.decide` and a role the policy names.
- When it runs, the requester must still be active and still hold the
  permission the action takes. Otherwise the request is marked failed.
- Every step goes on the security record: requested, approved, rejected,
  withdrawn, expired, executed, failed, bypassed.
- Approvers get an email (unless they turned `notifications.approval_requests`
  off) and a count beside Approvals in the console. The requester gets an email
  when their request is decided.

## Policies

Admin › Settings › Approval policies sets, per action: on or off, approvers
needed (1 to 3), the roles that may approve and how long a request stays open.

Everything ships off except admin access: granting, changing and revoking an
admin's role, and making, changing or deleting a custom role. The
policy over the policies (`approvals.policies`) is on whenever any other
policy is on, and cannot be switched off. So once one policy is on, changing
any policy, including turning one off, waits for approval too.

A platform with fewer possible approvers than a policy needs, such as a new
deployment with one owner, could otherwise not add the approver it lacks.
There the action goes through and `approval.bypassed` records it. Possible
approvers are counted whatever their status, so suspending the only other
approver does not open that path.

## Actions

| Key | Action |
|---|---|
| `organizations.approve`, `organizations.reject` | deciding on a signed-up organization |
| `organizations.suspend`, `organizations.reactivate` | an organization's suspension |
| `billing.record` | a payment: plan, agreed amount and term |
| `countries.switch` | a country on or off |
| `kill_switches.enable`, `.disable`, `.stop`, `.resume` | the kill switches |
| `features.override` | a feature off or on for one organization |
| `admins.grant`, `admins.invite`, `admins.role`, `admins.revoke` | admin access |
| `admin_roles.save`, `admin_roles.delete` | custom admin roles |
| `users.suspend`, `users.reactivate` | a person's account |
| `licences.issue`, `licences.revoke`, `licences.allowances` | licences |
| `accounts.freeze`, `accounts.unfreeze` | reserved for the payment freeze |
| `approvals.policies` | the policies themselves |

Each family implements `ApprovableActions`: `preview()` says what would change
and refuses what could never run, `execute()` does it as the requester. A new
action is a key in `ApprovalCatalogue::ACTIONS` and a family method. The admin
API refuses an action its policy holds (409): it is asked for in the console,
where it carries a reason and the approvers see it.
