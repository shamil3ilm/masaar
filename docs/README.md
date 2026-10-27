# Documentation

Audience: everyone. Start here and pick the section for your role.

## Business and sales

For business owners, sales and decision makers.

- [Overview](business/OVERVIEW.md) - what the platform does and for whom.
- [Features](business/FEATURES.md) - everything it offers, by area.
- [Saudi e-invoicing](business/SAUDI-EINVOICING.md) - ZATCA e-invoicing in plain words.
- [UAE e-invoicing](business/UAE-EINVOICING.md) - Peppol e-invoicing in the UAE in plain words.
- [Security and trust](business/SECURITY-AND-TRUST.md) - how your data and keys are protected.
- [FAQ](business/FAQ.md) - common questions.
- [Glossary](business/GLOSSARY.md) - the terms used across these documents.

## Users

For the people who use the portal and the console every day.

- [Getting started](users/GETTING-STARTED.md) - sign-up, approval, company setup, connecting to ZATCA.
- [Invoicing](users/INVOICING.md) - documents, statuses, drafts, notes, printing, history and exports.
- [Books](users/BOOKS.md) - purchases, input VAT, the VAT return draft, receivables and sales reports.
- [Team and settings](users/TEAM-AND-SETTINGS.md) - roles, approvals, settings, integrations and requests.
- [UAE](users/UAE.md) - start date, before e-invoicing, appointing a service provider.
- [Admin console](users/ADMIN-CONSOLE.md) - for platform operators.
- [Workflows](users/WORKFLOWS.md) - end-to-end flows as numbered steps.

## Integrators

For developers connecting an ERP, store or billing system.

- [Quickstart](integration/QUICKSTART.md) - the first API call to the first filed invoice.
- [API](integration/API.md) - the API end to end.
- [Advanced scenarios](integration/ADVANCED-SCENARIOS.md) - edge cases and less common flows.
- [Webhooks](integration/WEBHOOKS.md) - events and signature verification.
- [Saudi Arabia](integration/SAUDI.md) - the ZATCA endpoints.
- [UAE](integration/UAE.md) - the Peppol endpoints.
- [Simulator](integration/SIMULATOR.md) - testing UAE flows without a service provider.
- [Store connectors](integration/STORE-CONNECTORS.md) - connecting online stores.
- [OpenAPI description](openapi.yaml) - generated from the routes; generate a client from it.

## Developers

For people working on the code.

- [Setup](developers/SETUP.md) - a local environment.
- [Codebase tour](developers/CODEBASE-TOUR.md) - where things live.
- [Conventions](developers/CONVENTIONS.md) - naming and code rules.
- [Testing and gates](developers/TESTING-AND-GATES.md) - the suites and checks every change passes.
- [How-to](developers/HOW-TO.md) - common changes, step by step.
- [Contributing](../CONTRIBUTING.md) - how to propose a change.
- [Database schema](schema.sql) - generated from the migrations.

## Architecture

- [Overview](architecture/OVERVIEW.md) - the big picture.
- [Domains](architecture/DOMAINS.md) - the parts of the application.
- [Data flow](architecture/DATA-FLOW.md) - how an invoice moves through the system.
- [Filing states](architecture/FILING-STATES.md) - invoice and submission states.
- [Books and finance](architecture/BOOKS-AND-FINANCE.md) - the accountant's Books and the platform's own finance.
- [Access](architecture/ACCESS.md) - one access decision per feature.
- [Approvals](architecture/APPROVALS.md) - maker-checker for admin actions and notes.
- [Customization](architecture/CUSTOMIZATION.md) - defaults and overrides by level.
- [Jurisdiction routing](architecture/JURISDICTION-ROUTING.md) - how a country module is chosen.
- [Adding a jurisdiction](architecture/ADDING-A-JURISDICTION.md) - a new country, step by step.
- [Organization model](architecture/ORGANIZATION-MODEL.md) - organizations, branches, tills and people.
- [Tenant databases](architecture/TENANT-DATABASES.md) - shared and dedicated databases.
- [Audit schema](architecture/AUDIT-SCHEMA.md) - what the audit log records.

## Compliance and audit

For auditors, compliance officers and tax advisers.

- [Compliance overview](compliance/README.md) - where each rule is documented.
- [Auditor pack](compliance/AUDITOR-PACK.md) - what an auditor asks for, and where it is.
- [Policies](compliance/POLICIES.md) - retention, keys and record keeping.
- [Saudi Arabia](compliance/sa/README.md) - ZATCA rules, hashing and signing, and status.
- [UAE](compliance/ae/README.md) - PINT AE rules and provider onboarding.

## Operations

For the people who deploy and run the platform.

- [Deployment](operations/DEPLOYMENT.md) - installing and upgrading.
- [Staging](operations/STAGING.md) - the staging server.
- [Backup and restore](operations/BACKUP-AND-RESTORE.md) - backups and a rehearsed restore.
- [Key rotation](operations/KEY-ROTATION.md) - rotating secrets and signing keys.
- [Licensing](operations/LICENSING.md) - licences and usage reporting.
- [Partner deployment](operations/PARTNER-DEPLOYMENT.md) - running the platform for a partner.
- [Container registry](operations/DOCKER-REGISTRY.md) - publishing images.
- [Production readiness](operations/PRODUCTION-READINESS.md) - the checklist before going live.
- [Runbook: pilot simulation](operations/runbooks/PILOT-SIMULATION.md) - a pilot's first run in ZATCA's simulation environment.
- [Runbook: operations](operations/runbooks/OPERATIONS.md) - day-to-day tasks.
- [Runbook: incidents](operations/runbooks/INCIDENTS.md) - what to do when something breaks.

<!-- NEW DOCS: add documents created after this map here, under the right heading above. -->

## Project files

- [README](../README.md) - what the platform is and how to start.
- [Changelog](../CHANGELOG.md) - what changed.
- [Security policy](../SECURITY.md) - reporting a vulnerability.
- [Support](../SUPPORT.md) - getting help.
- [Contributing](../CONTRIBUTING.md) - working on the code.
- [License](../LICENSE) and [Terms](../TERMS.md).
