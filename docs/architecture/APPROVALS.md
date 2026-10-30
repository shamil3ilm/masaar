# Approvals (maker-checker)

Audience: engineers adding a held action, and platform owners and operators
setting approval policies.

An admin action its policy holds back does not run when asked. It becomes a
request with the requester's reason, a preview of what it would change
(before and after) and an expiry. It runs, exactly as requested and through
the same code as the direct action, once enough approvers approve; it ends
when an approver rejects it with a reason, the requester withdraws it, or
it lapses.

## Rules

- Nobody approves their own request, or a change to their own access.
- An approver needs `approvals.decide` and a role the policy names.
- At execution the requester must still be active and hold the action's
  permission; otherwise the request is marked failed.
- Every step is on the security record: `approval.requested`, `.approved`,
  `.rejected`, `.withdrawn`, `.expired`, `.executed`, `.failed`,
  `.bypassed` (and `.approved_from_server` for recovery).
- Approvers are emailed (unless `notifications.approval_requests` is off)
  and see a count in the console; the requester is emailed the outcome.

## Policies

Admin › Settings › Approval policies sets, per action: on or off, approvers
needed (1 to 3), the roles that may approve (owner and operations as
shipped) and how long a request stays open (72 hours unless set, at most
720).
<!-- app/Domains/Platform/Approvals/ApprovalPolicies.php: RISKY, DEFAULT_APPROVERS, MAX_APPROVALS, DEFAULT_HOURS, MAX_HOURS -->

Everything ships off except admin access (`admins.*`, `admin_roles.*`),
`certificates.revoke`, which stops a till signing, and
`zatca_environment.production`, which moves a taxpayer onto the live
gateway or off it. `approvals.policies`, the
policy over the policies, is on whenever any other policy is on and cannot
be switched off.

**Bypass.** While **Allow bypass when no other approver exists** is on (as
shipped), an action no one else could approve goes through, recorded as
`approval.bypassed` (`why: bypassed: no other approver exists`) with the
values and reason, and the owner is emailed each time. Possible approvers are
counted whatever their status. The setting is the owner's, and can be turned
off only while another active admin who decides approvals exists.

**Recovery.** A sole owner locked out anyway runs, on the server:

```
php artisan platform:owner-recover --enable-bypass
php artisan platform:owner-recover --approve=<request id>
```

One thing per run; refused while another admin could approve; asks for a
reason and confirmation (`--force` requires `--reason`); recorded as
`platform_owner.recovered` and emailed to the owner.

## One platform owner

The console has exactly one owner (`Auth\Services\PlatformOwnership`).
Ownership is only handed on: the owner offers it to an active admin with
two-factor sign-in who decides approvals, naming the role they step down to;
the admin accepts; both change in one transaction. Nothing else makes an
owner - role changes, grants, invitations and the admin API refuse the role,
a custom role cannot hold `admins.manage`, and `platform:create-admin`
refuses once an owner exists. Migration `0940_single_platform_owner`
reduced existing data to one owner, recording each demotion. Customer
organizations keep their own owner and admin roles (`MemberRole`).

## Actions

| Key | Action |
|---|---|
| `organizations.approve`, `.reject`, `.suspend`, `.reactivate` | An organization's review and suspension |
| `billing.record` | A payment: plan, agreed amount and term |
| `finance.write_off` | Writing off what a customer owes on an invoice |
| `finance.supplier_payment` | A supplier payment of `finance.large_payment_threshold` or more |
| `finance.expense_write_off` | Writing off what is owed to a supplier on an expense |
| `countries.switch` | A country on or off |
| `kill_switches.enable`, `.disable`, `.stop`, `.resume` | The kill switches |
| `features.override` | A feature off or on for one organization |
| `organizations.language_grace` | Granting or extending a language grace |
| `admins.grant`, `.invite`, `.role`, `.revoke`; `admin_roles.save`, `.delete` | Admin access and custom roles |
| `users.suspend`, `.reactivate` | A person's account |
| `licences.issue`, `.revoke`, `.allowances` | Licences |
| `api_keys.production` | A production API key a customer's administrator asked for |
| `certificates.revoke` | Marking a device certificate revoked (Saudi module, added with `ApprovalCatalogue::extend()`) |
| `zatca_environment.production` | Switching a Saudi organization's ZATCA environment to or from production (Saudi module, `EnvironmentSwitchActions`); a switch between sandbox and simulation is not held |
| `accounts.freeze`, `.unfreeze` | Freezing a customer's account by hand, or lifting it until a date |
| `approvals.policies` | The policies themselves |

Each family implements `ApprovableActions`: `preview()` says what would
change and refuses what could never run; `execute()` does it as the
requester. A new action is a key in `ApprovalCatalogue::ACTIONS` and a
family method; a country module adds its own with `extend()` from its
service provider. The admin API answers `409` for an action its policy
holds. A customer's request (`api_keys.production`) implements
`RequestedByCustomers`: at execution the customer must still administer the
organization.

## Organization approvals

An organization can require a second person for its own credit and debit
notes (`OrganizationApprovals`, `Invoice\Services\NoteIssuing`), on the same
request records, scoped to the organization and never shown in the console.

- **Policy.** Settings › Approvals: `approvals.notes` (the platform default
  is off; `Organization\Services\Registrar::register` sets it on for every
  new organization, so organizations registered before keep their value; and
  required before a Saudi organization is switched to ZATCA production:
  `EnvironmentSwitch::set` refuses the switch while it is off) and
  `approvals.notes_threshold` (only notes of at least that total). A
  platform default and the organization's value; no per-person value.
- **Flow.** Issuing a held note in the portal makes a request and the note
  stays a draft. Another owner or admin approves or rejects it; the maker
  may withdraw; it lapses after fourteen days. Approved, it is issued as its
  maker, with every pre-counter check run again; a note changed since is
  refused and the request fails. With nobody else to approve, nothing is
  bypassed: the request waits like any other, its `approval.requested`
  record says `no_other_approver: true`, and the organization's owners and
  admins are emailed (`ApprovalWithoutApproverMail`) to add an approver or
  turn the policy off and issue again.
- **API and pipeline.** A held note sent through `/pipeline/submit`,
  `/pipeline/batch`, `/compliance/generate/{id}` or the UAE `send` stays a
  draft and the call answers 202 with the request
  (`OrganizationApprovals::submitFromIntegration`). Made with a licence key,
  the request has no requester (`requested_by` null; the payload names the
  channel and the key): any approver decides it, and it is issued as the
  approver. Made with a person's token, it is theirs. A retry answers with
  the open request ([PIPELINE.md](../integration/PIPELINE.md#notes-held-for-approval)).
- **Not held.** Notes the platform issues itself (billing refunds, settled
  insurer splits).
