# Tenant databases

Every organization lives in one shared database today, isolated by `org_id`
(`BelongsToTenant`, `TenantScope`). The plan is to keep that as the default
and, when a partner needs it, point that partner's organizations at a
database of their own. This page records what in the code would stand in the
way, and the approach.

## What stays shared, and what moves

Shared, in the platform database whatever happens: people and sign-in
(`users`, sessions, two-factor), organizations and memberships
(`organizations`, `organization_user`, invitations), licences and their
metering, billing, platform settings and customizations, admin roles and
approvals, support, and the admin audit trail.

Per organization, and so movable: invoices and their lines, submissions and
their state logs, the hash chain, the offline queue, late reports and
reporting attempts, issuing locks, Peppol documents, received documents,
branches and compliance profiles, customers and products, webhooks and their
logs, and the organization's own audit entries.

## Findings

**Raw queries on the default connection.** `DB::table()` always uses the
default connection, whatever the models do. Tenant tables are queried raw in:

- console reports that look across every organization: `ChainHealth`,
  `OrganizationReport`, `PlatformStatus`, `QueueReport`, `SubmissionLog`,
  `UserReport`, `MetricsCollector` (declared in `RawTenantQueryTest`);
- licence usage roll-ups: `UsageReportingService`;
- filing internals: `SubmissionLedger`, `LateReporting`, `ReportingAttempts`,
  `IssuingLifecycle`, `ProcessOfflineQueue`, `ProcessFatooraSubmission`;
- `Team` and `AuditReport` read `audit_logs` raw for activity.

The cross-organization reports cannot become one query once a partner has its
own database: they have to ask each connection and merge. The others act for
one organization and only need the organization's connection.

**Joins between a tenant table and a shared one.** A join cannot cross
databases. `SubmissionLog`, `QueueReport`, `OrganizationReport` and
`AuditReport` join tenant tables to `organizations`; `SubmissionReport`
joins `invoice_submissions` to `users`. Joins between two tenant tables
(`late_reports` to `invoices`, submissions to invoices) are fine, since both
move together.

**Transactions that write both sides.** About forty services open
`DB::transaction()` on the default connection. Several write tenant and
shared tables together - issuing writes the invoice and the organization's
audit entry, metering reserves licence quota around a submission. Across two
databases these become two transactions with no shared commit; each place
needs an order that is safe to retry (tenant write first, then the shared
record, or an outbox).

**Foreign keys to shared tables.** Tenant tables reference `organizations`
and `users` by foreign key. A partner database cannot hold those constraints;
its copy of the schema drops them and the application keeps the check.

**Queue jobs.** `ProcessFatooraSubmission` is given the submission model, and
Laravel restores a queued model on the connection it was read from, so it
follows the organization. `SendPeppolDocument` and `DeliverWebhook` are given
ids only and look them up on the default connection: they need the
organization's id in the payload, or the job has to resolve the connection
before it reads. The `DispatchInvoiceWebhook` listener queues
`DeliverWebhook` the same way.

**Caches** are keyed by UUIDs, which are unique across databases, so nothing
collides when organizations sit in different databases.

**Checks across organizations.** A VAT number already registered to another
organization is refused at registration by one query over `organizations`,
which stays shared, so that check keeps working.

No blocker here is a one-line fix: each needs the connection resolver below
first, so none is changed yet.

## Approach

1. **One resolver.** A `TenantConnections` service answers the connection
   name for an organization id: the default, or the partner's, from a
   `database` column on the organization's group, configured per deployment.
   Cached like every other per-organization answer.
2. **Tenant models follow it.** `BelongsToTenant` overrides
   `getConnectionName()` with the resolver for the model's `org_id`, or the
   current tenant's. Model queries, relations and queued models then go to
   the right database without changing call sites.
3. **Raw queries ask for it.** Every `DB::table()` on a tenant table becomes
   `DB::connection($connections->for($organizationId))->table()`, and the
   cross-organization reports loop over the connections in use and merge.
   `RawTenantQueryTest` already lists every such file, which makes it the
   work list.
4. **Joins split.** Tenant-to-shared joins become two reads: the tenant rows,
   then the names for the ids they carry.
5. **Transactions on the tenant's connection,** with the shared write after
   it commits.
6. **Jobs carry the organization.** Id-only jobs take the organization id and
   resolve the connection first; a job that reads a model keeps working.
7. **Migrations per connection.** Tenant tables are migrated on every tenant
   connection; shared tables only on the platform's.

Until a partner asks for its own database, steps 1 and 2 cost nothing to
leave out: every organization resolves to the default connection.
