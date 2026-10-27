# Team and settings

Audience: company owners and admins who run the organization in the portal.

## Roles

| Role | What they can do |
|---|---|
| Owner | Everything an admin does, and decides who are owners. |
| Admin | Company details, numbering, the authority connection and the team. |
| Member | Prepares and issues invoices. |
| Accountant | Reads everything, runs reports and exports, and sees the plan's invoices. Records purchases and payments in Books, and changes nothing else. |
| Viewer | Reads everything and changes nothing. |

Only an owner can give or take the owner role. An organization always keeps an
owner or an admin.

Owners and admins see **Organization settings** in the menu: Company, Activity,
Branches, Settings, Team, Integrations, the authority's pages, Numbering and
Branding. **Plan and billing** is for owners, admins and accountants.
**Referrals** is for owners and admins.

<!-- source: app/Domains/Organization/Enums/MemberRole.php; app/Domains/Organization/Services/Team.php; resources/views/layouts/portal.blade.php -->

## Team

**Team** lists members, invitations waiting and removed people.

- **Invite someone** by email, with a role. You can withdraw an invitation.
- **Change role** takes effect at once.
- **Remove** ends access at once. **Bring back** restores the role they had.

Branches decide where people work. Someone assigned to one or more branches
sees and issues at those alone. Administrators work at every branch. Each
person picks their working branch from the switcher.

**Activity** shows who issued, corrected and changed what, with a CSV export.

<!-- source: resources/views/portal/team.blade.php; resources/views/portal/branches/ -->

## Approvals for notes

Under **Settings > Approvals** an admin can turn on **Credit and debit notes
need a second person**, optionally only above a total.

When it is on:

1. A person issues a note. It waits in **Approvals**.
2. Another owner or admin opens **Approvals** and chooses **Approve** or
   **Reject** (with a reason). Nobody approves their own.
3. Approved, the note is issued with every check run again. Rejected, it stays
   a draft and the person sees the reason. The person who asked can
   **Withdraw** the request.

If nobody else can approve, the note is issued and the activity record says so.
Notes the platform issues itself (refunds, insurer decisions) are not held.

<!-- source: app/Domains/Platform/Customization/Catalogue.php (approvals.notes); resources/views/portal/approvals.blade.php; app/Domains/Platform/Approvals/OrganizationApprovals.php -->

## Settings and preferences

Every setting has a platform default. Your organization can set its own, and
each person can set their own where it makes sense. The nearest one wins. Each
setting shows what is in effect and where it comes from. Reset it to fall back.

- **Settings** (admins) - for everyone in the organization: prices include
  VAT, common charges, type "Issue" to confirm, how schedules bill, require
  two-factor sign-in, approvals for notes, how long the API request log is
  kept, and the language printed first.
- **Preferences** (everyone) - your own: language, working branch, email
  notifications, and your own choices for the settings above.
- **Printing** - paper per person, branch and organization, and receipt printer details.
- **Numbering** - pattern, counter digits, when the counter restarts, and
  whether each till has its own series.
- **Branding** - your logo, colours and invoice template, with a preview.
- **QR codes** - your contact card, payment details, location or a link to one
  invoice. One extra QR code can print on invoices, apart from the tax authority's.

How the levels work in detail: [CUSTOMIZATION.md](../architecture/CUSTOMIZATION.md).

<!-- source: app/Domains/Platform/Customization/Catalogue.php; resources/views/portal/settings.blade.php; resources/views/portal/preferences.blade.php; resources/views/portal/numbering.blade.php; resources/views/portal/qr-codes.blade.php -->

## Integrations

Admins connect an ERP, store or their own software under **Integrations**.

- **API keys** - **Make an API key** with a name, its environment, what it may
  do, and optionally an expiry and allowed addresses. A **Sandbox** key never
  files with the live authority. A **Production** key files for real and may
  wait for a platform administrator's approval. The secret is shown once.
  **Revoke** stops the key at once.
- **Webhooks** - **Add a webhook** with an HTTPS endpoint and the events it
  needs. The signing secret is shown once. You can rotate it, send a test
  event and see recent deliveries.
- **Request log** - every API call in recent days, with no bodies or secrets.

For developers: the integration guides listed in [the documentation map](../README.md).

<!-- source: resources/views/portal/integrations/keys.blade.php; resources/views/portal/integrations/webhooks.blade.php; resources/views/portal/integrations/requests.blade.php -->

## Requests and support

**Requests** is where you ask us for something. Everyone in the company sees them.

- Kinds: **Support**, **Customization** (work done for your company) and
  **Feature request**.
- Mark one **Urgent** if invoicing has stopped.
- For a customization we agree the work and its price with you before we
  start. Nothing is charged before you agree.
- **My problem is solved** closes a ticket.

The **Feature board** shows what customers asked for, with no company named.
Press **We need this too** to back an item, or **Follow** it for updates.

<!-- source: resources/views/portal/support/; app/Domains/Support/Enums/ -->

## Plan and billing

**Plan and billing** shows your plan, this month's use, our invoices and your
payments. Pay by bank transfer quoting the invoice number, or **Pay by card**
where card payments are offered. To renew or change plan, open a request.
