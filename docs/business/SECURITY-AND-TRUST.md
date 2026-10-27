# Security and trust

Audience: IT leads, security reviewers and procurement teams assessing the platform.

This page states only what the platform does today. Anything not built yet is marked **planned**. For contract-specific questions, such as hosting region confirmation or a security questionnaire, write to **shamil3ilm@gmail.com**.

## Where your data lives

- **Saudi taxpayers.** ZATCA requires a taxpayer resident in the Kingdom to keep its records electronically on a computer or server located inside the Kingdom (*Guideline for Tax Invoicing and Records under VAT Provisions*, §8.3.1). The hosting region is a deployment setting, not something the application decides, so it is confirmed per deployment and in your contract.
- **UAE taxpayers.** The UAE guidelines accept storage in any location, provided the records can be retrieved and handed to the FTA promptly and in full (*UAE Electronic Invoicing Guidelines* v1.1, §5.4).

## Encryption

- **In transit.** In production every page and API call is served over HTTPS. Webhooks to your systems must use HTTPS, and connections to the tax authorities are encrypted.
- **At rest, inside the application:**
  - ZATCA signing keys and certificates are encrypted with a key kept apart from the application's main key.
  - Two-factor secrets, recovery codes, webhook signing secrets and invoice-link tokens are encrypted in the database.
  - Sessions are encrypted in production.
  - API keys are stored only as hashes.
- **Backups** are encrypted with AES-256. A backup will not run without an encryption password, and the encryption keys are never stored in the backup itself.
- **Disk-level encryption** of servers and databases depends on the hosting set-up. We confirm it per deployment.

## Key handling

- Each till or device signs with its own ZATCA certificate. Expiry is checked daily and administrators are warned in advance. An expired certificate cannot sign. In production, a certificate is also checked against ZATCA's revocation list before it signs, and a revoked certificate cannot sign.
- Every earlier version of a certificate is kept, so any past invoice can still be traced to the certificate that signed it.
- The key that protects signing material can be rotated without re-issuing certificates.
- **Planned:** a separate encryption key for each organization, held in a managed key service. Today one platform key protects every organization's signing material.

## Sign-in and access

- **Two-factor sign-in** with an authenticator app, plus single-use recovery codes.
- **Mandatory two-factor.** An organization can require two-factor sign-in for every member. Platform administrators must always use it.
- **Passwords.** Passwords must be strong, and passwords known from public data breaches are refused.
- **Sessions.** You can sign out your other sessions, and a password change ends sessions elsewhere.
- **Limits.** Sign-in and sensitive actions are rate-limited.
- **Roles:**
  - In your organization: Owner, Admin, Member, Accountant and Viewer.
  - For platform staff: their own roles, with each action tied to a named permission.
- **Approvals.** Optionally, a credit or debit note waits for a second person's approval, and no one can approve their own request. Selected platform-administrator actions also need a second administrator.
- **Isolation.** Every query is scoped to your organization. Automated tests check that one organization cannot read another's records.

## Audit log

Every issue, correction, setting change, invitation and approval is recorded with who did it and when. Failed sign-ins and every administrator change are recorded too. Your administrators can read and export your organization's activity as CSV. Each API call carries a request ID, which links your system's request to the platform's records.

## Records, retention and backups

- **Invoices and their filing history are never deleted.** This follows ZATCA's rule that issued invoices must not be altered or deleted. Corrections are made with credit and debit notes.
- **Retention.** Saudi records are kept for at least six years. UAE records are kept for at least five years after the tax period. Scheduled maintenance never deletes compliance records.
- **Daily encrypted backups** of the database and the certificate store, copied off the server. Each backup is opened and checked after it is written, and an alert fires if the newest backup is more than a day old. Backups are kept daily for 30 days, weekly for 12 weeks, monthly for 12 months and yearly for six years. Nothing is pruned to save space.
- **Your data stays readable.** Even if your account is suspended, you can still read and export your own records.

## Availability approach

- **Authority outages are expected and handled.** Invoices are queued and retried automatically, the 24-hour reporting deadline keeps being tracked, and the platform backs off a tax-authority endpoint that is down instead of flooding it.
- **Separate work queues.** UAE sending runs on its own queue, so a slow provider in one country does not delay the other.
- **Health checks** for the application, its readiness and its connection to each authority. Unfinished submissions are recovered every few minutes, and the hash chain is verified weekly.
- **Emergency stops.** Platform operators can pause issuing or signing for everyone or for one organization. Invoices already issued keep being filed.
- **No uptime guarantee yet.** Service levels are agreed in your contract.

## Reporting a vulnerability

Write to **shamil3ilm@gmail.com**. We acknowledge reports promptly and do not take action against good-faith researchers.

Related: [Overview](OVERVIEW.md) · [FAQ](FAQ.md)
