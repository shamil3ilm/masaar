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

Everything ships off except admin access - granting, inviting, changing and
revoking an admin, and making, changing or deleting a custom role - and
marking a device certificate revoked, which stops a till signing. The
policy over the policies (`approvals.policies`) is on whenever any other
policy is on, and cannot be switched off. So once one policy is on, changing
any policy, including turning one off, waits for approval too.

Approvers as shipped are the owner and operations: the platform has one
owner, and an owner-only policy could never be met when the owner asks.

### When no other approver exists

A platform with fewer possible approvers than a policy needs, such as a new
deployment with only its owner, could otherwise not add the approver it
lacks. While **Allow bypass when no other approver exists** is on (as
shipped), the action goes through:

- the security record says `approval.bypassed` with
  `why: bypassed: no other approver exists`, and keeps the summary, the values
  before and after and the reason given;
- the owner is emailed every time, in their own language, with what changed,
  by whom and why.

With it off, such a request waits like any other, and lapses if nobody can
decide it. The setting is the owner's alone (Admin › Settings › Approval
policies), and changing it is itself a policy change: under approval once
someone else can approve.

Possible approvers are counted whatever their status, so suspending the only
other approver does not open the bypass.

Bypass is turned off only while at least one other active admin, in a role
that decides approvals, exists. Otherwise the change is refused, saying why:
with nobody else to approve, every action a policy holds, the owner's own
included, would wait until it lapsed.

### Recovering a locked-out owner

If a sole owner is locked out all the same (bypass off and no other admin
left who could approve), someone with access to the server runs:

```
php artisan masaar:owner-recover --enable-bypass
php artisan masaar:owner-recover --approve=<request id>
```

It does one thing per run: turns **Allow bypass when no other approver
exists** back on, or approves one pending request (recorded as approved from
the server, with no admin as its approver). It refuses while another admin
could approve, since the owner is then not locked out. It asks for a reason
and a confirmation; for automation, `--force` skips the confirmation and then
requires `--reason`. Every run goes on the security record
(`platform_owner.recovered`, with the reason) and the owner is emailed, so a
run they did not make is noticed. It is a command and not a console screen
on purpose: only server access reaches it.

## One platform owner

The admin console has exactly one owner (`PlatformOwnership`). Ownership is
never granted, only handed on: the owner offers it to one active admin who
has two-factor sign-in and decides approvals, naming the role they will step
down to (operations unless they choose another); the admin accepts; then, in
one transaction, the admin becomes owner and the old owner takes the named
role. Offer, withdrawal and transfer are on the security record.

Nothing else makes an owner. A role change, a grant, an invitation and the
admin API refuse the owner role; a custom role cannot hold `admins.manage`,
the owner's own permission; `masaar:create-admin` makes the first owner and
refuses, with or without `--promote`, once there is one. The owner is never
demoted, revoked or suspended directly.

Existing data was brought to one owner by migration 0940: the earliest-created
active owner stays, every other became operations, each demotion on the
security record (`security.platform_owner.demoted_to_single_owner`).

Customer organizations are not covered: their own owner and admin roles
(`MemberRole`) stay as they are, at least one owner or admin and possibly
several.

## Actions

| Key | Action |
|---|---|
| `organizations.approve`, `organizations.reject` | deciding on a signed-up organization |
| `organizations.suspend`, `organizations.reactivate` | an organization's suspension |
| `billing.record` | a payment: plan, agreed amount and term |
| `countries.switch` | a country on or off |
| `kill_switches.enable`, `.disable`, `.stop`, `.resume` | the kill switches |
| `features.override` | a feature off or on for one organization |
| `organizations.language_grace` | granting or extending an organization's language grace (withdrawing one is never held) |
| `admins.grant`, `admins.invite`, `admins.role`, `admins.revoke` | admin access |
| `admin_roles.save`, `admin_roles.delete` | custom admin roles |
| `users.suspend`, `users.reactivate` | a person's account |
| `licences.issue`, `licences.revoke`, `licences.allowances` | licences |
| `certificates.revoke` | marking a device certificate revoked (Saudi module; the CA's own revocation list is never held) |
| `accounts.freeze`, `accounts.unfreeze` | reserved for the payment freeze |
| `approvals.policies` | the policies themselves |

Each family implements `ApprovableActions`: `preview()` says what would change
and refuses what could never run, `execute()` does it as the requester. A new
action is a key in `ApprovalCatalogue::ACTIONS` and a family method; a
country module adds its own with `ApprovalCatalogue::extend()` from its
service provider, saying whether it ships held. The admin
API refuses an action its policy holds (409): it is asked for in the console,
where it carries a reason and the approvers see it.
