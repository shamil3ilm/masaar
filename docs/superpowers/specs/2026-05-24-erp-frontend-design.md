# ERP Frontend — Design Specification

**Date:** 2026-05-02
**Status:** Approved
**Scope:** Full frontend for the multi-tenant ERP backend (`erp-backend`), starting with the ZATCA compliance module

---

## Table of Contents

1. [Overview](#1-overview)
2. [Technology Stack](#2-technology-stack)
3. [Repository Structure](#3-repository-structure)
4. [The Three Portals](#4-the-three-portals)
5. [Authentication & Multi-tenancy](#5-authentication--multi-tenancy)
6. [ZATCA Module — First Module to Build](#6-zatca-module--first-module-to-build)
7. [Shared Layout & Navigation](#7-shared-layout--navigation)
8. [Data Fetching & State Management](#8-data-fetching--state-management)
9. [Testing Strategy](#9-testing-strategy)
10. [Development & Deployment](#10-development--deployment)
11. [Getting Started — Step by Step](#11-getting-started--step-by-step)

---

## 1. Overview

The ERP frontend is a separate project from the Laravel backend. It lives at `c:\laragon\www\erp-frontend` and connects to the backend exclusively through the REST API at `/api/v1`.

### Why separate from the backend?

The backend is an API server. Keeping the frontend in its own repository means:
- They can be deployed and updated independently
- Frontend developers do not need PHP or Laravel installed
- The frontend can be hosted anywhere (CDN, separate server, etc.)

### Three user types, three apps

The system serves three distinct groups of users, each with different needs:

| Portal | Who uses it | What they do |
|--------|-------------|--------------|
| **Staff app** | Accountants, compliance officers, managers | Day-to-day ERP operations |
| **Admin app** | Super-admins | Manage tenants, organizations, system settings |
| **Vendor portal** | Suppliers, customers | View and download invoices shared with them |

---

## 2. Technology Stack

### What each tool does

| Tool | Purpose | Why chosen |
|------|---------|------------|
| **React 19** | UI library — builds the interface from components | Industry standard, largest ecosystem |
| **TypeScript** | Adds types to JavaScript — catches bugs before runtime | Essential for large codebases |
| **Vite** | Build tool — compiles and bundles the app | Already configured in the backend, extremely fast |
| **TanStack Router** | Handles URL routing within each app | Type-safe, file-based, modern replacement for React Router |
| **TanStack Query** | Fetches and caches data from the API | Handles loading/error states, caching, refetching automatically |
| **Zustand** | Stores global UI state (auth, selected org) | Lightweight, simple, no boilerplate |
| **shadcn/ui** | Pre-built UI components (buttons, forms, dialogs) | Fully customizable, you own the code |
| **Tailwind CSS v4** | Utility-first styling | Already in the backend, consistent design |
| **AG Grid Community** | Heavy data grid for tables with thousands of rows | Industry standard for ERP-grade tables |
| **React Hook Form** | Handles form state and submission | Performant, works well with Zod |
| **Zod** | Schema validation for forms and API responses | Type-safe validation, mirrors Laravel validation rules |
| **Axios** | HTTP client for API calls | Interceptors for JWT auth, error handling |
| **MSW** | Mocks API responses during development and testing | Test without a running backend |
| **Vitest** | Unit and integration test runner | Fast, built for Vite projects |
| **Playwright** | End-to-end browser tests | Reliable, cross-browser |
| **Storybook** | Component documentation and visual testing | See components in isolation |
| **Turborepo** | Manages multiple apps in one repository | Shared code, fast builds, run all apps at once |
| **pnpm** | Package manager (required by Turborepo) | Faster than npm, efficient disk usage |

---

## 3. Repository Structure

The frontend uses a **monorepo** — one repository containing multiple apps and shared packages.

```
erp-frontend/
│
├── apps/                          # The three user-facing applications
│   ├── staff/                     # Main ERP app (largest)
│   ├── admin/                     # Super-admin panel (medium)
│   └── portal/                    # Vendor/customer portal (lightweight)
│
├── packages/                      # Shared code used by all apps
│   ├── ui/                        # Shared UI components (shadcn/ui + custom)
│   ├── api-client/                # API hooks, Axios instance, MSW mocks
│   └── types/                     # TypeScript types matching backend models
│
├── turbo.json                     # Turborepo configuration
├── pnpm-workspace.yaml            # Declares all apps and packages as a workspace
└── package.json                   # Root-level scripts (e.g. pnpm dev runs everything)
```

### What goes in `packages/`

**`packages/types`** — TypeScript interfaces that mirror the Laravel backend's response shapes. Example:

```ts
// Matches the backend's ZatcaInvoice model/resource
export interface ZatcaInvoice {
  id: string
  invoice_number: string
  buyer_name: string
  total_amount: number
  vat_amount: number
  status: 'pending' | 'submitted' | 'cleared' | 'rejected'
  submitted_at: string | null
  zatca_uuid: string | null
}
```

**`packages/api-client`** — All API communication lives here. Each module has its own file of TanStack Query hooks:

```ts
// packages/api-client/src/zatca.ts
export function useZatcaInvoices(filters) { ... }
export function useSubmitInvoice() { ... }
export function useZatcaOnboardingStatus(branchId) { ... }
```

**`packages/ui`** — Shared components used across all three apps. Components here are built on shadcn/ui and can be used by staff, admin, and portal apps without duplication.

### Why a monorepo?

Without a monorepo, if you change the `ZatcaInvoice` type in the types package, you'd have to update three separate repositories. With a monorepo, one change propagates everywhere automatically.

---

## 4. The Three Portals

### Staff App (`apps/staff`)

The main ERP application. This is where the majority of development happens.

- Full sidebar navigation with all ERP modules
- Permission-aware (menu items hidden based on user role)
- AG Grid for all heavy data tables
- All ZATCA screens (see Section 6)
- Modules added progressively: Compliance → Accounting → Sales → Purchase → Inventory → HR → Manufacturing

### Admin App (`apps/admin`)

A separate, minimal app for super-admins who manage the platform itself.

- Flat navigation (no sidebar tree)
- Screens: Tenants, Users, Module Access, Audit Logs, System Settings
- Completely isolated from the staff app — different login, different JWT validation

### Vendor Portal (`apps/portal`)

The lightest of the three apps. Accessed via a magic link or token in the URL.

- No login form — auth is embedded in the URL token
- Shows a single invoice or a list of invoices shared with the vendor
- Download PDF button
- No navigation — just the invoice viewer
- Small bundle size (does not include AG Grid or full component library)

---

## 5. Authentication & Multi-tenancy

### How login works (Staff app)

```
1. User visits /login
2. Enters email + password
3. Frontend sends POST /api/v1/auth/login
4. Backend returns { token, user, organizations[] }
5. If user belongs to one org → go straight to /app/dashboard
6. If user belongs to multiple orgs → show org picker screen
7. User picks an org → store selection → go to /app/dashboard
```

### Where the token is stored

- **Zustand store (memory)** — used for all runtime checks
- **localStorage** — persists across page refreshes

On every API request, Axios automatically attaches:
```
Authorization: Bearer <token>
X-Organization-Id: <selected-org-id>
```

### Switching organizations

If a user has access to multiple organizations (common in multi-tenant ERPs):
1. Org switcher appears in the top bar
2. Clicking a different org clears all cached data (TanStack Query cache is reset)
3. Sets the new org ID in Zustand and localStorage
4. All subsequent API requests use the new org ID

### Route protection

Every protected page has a `beforeLoad` guard (TanStack Router feature):
- No token → redirect to `/login`
- Token exists but user lacks permission → redirect to `/403`
- Token expired → attempt refresh → retry → logout if refresh fails

### Token refresh

When a 401 (Unauthorized) response is received:
1. Axios interceptor catches it
2. Sends POST `/api/v1/auth/refresh` with the current token
3. Gets a new token back → retries the original request
4. If refresh also fails → clears auth state → redirects to login

### Admin app auth

Separate login at `/admin/login`. Same JWT mechanism but validates that the user has the `super_admin` role. Non-super-admins who try to access the admin app are rejected even with a valid token.

### Vendor portal auth

No login form. The magic link contains a signed token:
```
https://portal.erp.com/invoice/abc123?token=xyz789
```
The frontend sends this token with every request. The backend validates it and returns only the data that token is authorized to see.

---

## 6. ZATCA Module — First Module to Build

ZATCA (Zakat, Tax and Customs Authority) is Saudi Arabia's e-invoicing authority. The system must register each branch device and submit all B2B invoices electronically.

### The full lifecycle

```
1. Onboarding
   └── Generate CSR (Certificate Signing Request)
   └── Request CCSID (Compliance Certificate)
   └── Run compliance check (ZATCA validates test invoices)
   └── Upgrade to PCSID (Production Certificate)

2. Invoice Submission
   └── Create invoice
   └── Sign with production certificate
   └── Submit to ZATCA
   └── Receive clearance (or rejection)

3. Reporting
   └── View submission statistics
   └── Track rejection rates
   └── Monitor compliance status per branch
```

### Screen map

```
/app/compliance/zatca/
├── onboarding/
│   ├── index           → Dashboard showing onboarding status per branch
│   ├── ccsid           → CCSID request form + current status
│   └── pcsid           → PCSID upgrade form + current status
├── invoices/
│   ├── index           → AG Grid table of all e-invoices
│   ├── $invoiceId      → Invoice detail page + XML preview
│   └── create          → New B2B e-invoice form
└── reports/
    └── index           → Compliance summary dashboard
```

### Key UI components

**`ZatcaOnboardingWizard`** — A 3-step wizard component:
- Step 1: Generate and display CSR
- Step 2: Submit to ZATCA, show CCSID response
- Step 3: Upgrade to production (PCSID)
- Each step shows status: Not Started / In Progress / Complete / Failed

**`ZatcaStatusBadge`** — Color-coded badge showing invoice status:
- Grey: Pending
- Blue: Submitted
- Green: Cleared
- Red: Rejected

**`InvoiceXmlPreview`** — Collapsible panel that shows:
- Human-readable invoice summary on the left
- Raw UBL XML on the right (for technical debugging)

**`ComplianceStatsCard`** — Dashboard card showing:
- Total invoices submitted today / this month
- Rejection rate percentage
- Last successful sync timestamp

### AG Grid invoice table columns

| Column | Description |
|--------|-------------|
| Invoice # | Unique invoice number |
| Buyer | Customer name |
| Amount | Total invoice amount |
| VAT | VAT amount |
| Submitted | Submission date/time |
| Status | ZatcaStatusBadge |
| Actions | View / Retry (for rejected) |

The table uses **server-side row model** — it does not load all invoices at once. It requests one page at a time from the backend as the user scrolls or pages through.

### API hooks for ZATCA

All live in `packages/api-client/src/zatca.ts`:

```ts
useZatcaOnboardingStatus(branchId)   // GET onboarding state for a branch
useRequestCcsid(branchId)            // POST to request compliance certificate
useUpgradeToPcsid(branchId)          // POST to upgrade to production certificate
useZatcaInvoices(filters)            // GET paginated invoice list
useZatcaInvoice(invoiceId)           // GET single invoice detail
useSubmitInvoice(invoiceId)          // POST to submit invoice to ZATCA
useRetryInvoice(invoiceId)           // POST to retry a rejected invoice
useComplianceReport(dateRange)       // GET compliance statistics
```

---

## 7. Shared Layout & Navigation

### Staff app layout

```
┌────────────────────────────────────────────────────────────┐
│  TopBar                                                    │
│  [≡ Logo]  [Org: Acme Corp ▼]  [🔔 3]  [John Doe ▼]      │
├──────────────┬─────────────────────────────────────────────┤
│              │                                             │
│  Sidebar     │   Page content                             │
│              │                                             │
│  Dashboard   │   (Each route renders here)                │
│  ─────────   │                                             │
│  Compliance  │                                             │
│   └ ZATCA    │                                             │
│  Accounting  │                                             │
│  Sales       │                                             │
│  Purchase    │                                             │
│  Inventory   │                                             │
│  HR          │                                             │
│  Manufactur. │                                             │
│              │                                             │
└──────────────┴─────────────────────────────────────────────┘
```

- Sidebar is **collapsible** (icon-only mode on small screens)
- Sidebar items are **permission-aware**: if the user's role does not include a module, that section is hidden entirely
- The org switcher in the top bar shows all organizations the current user belongs to
- Notification bell shows unread count from the backend

### Admin app layout

Simpler — no sidebar module tree:

```
┌────────────────────────────────────────────────────────────┐
│  [Logo — ERP Admin]                           [Admin ▼]   │
├────────────────────────────────────────────────────────────┤
│  Tenants | Users | Module Access | Audit Logs | Settings  │
├────────────────────────────────────────────────────────────┤
│                                                            │
│   Page content                                             │
│                                                            │
└────────────────────────────────────────────────────────────┘
```

### Vendor portal layout

Minimal — no navigation at all:

```
┌────────────────────────────────────────────────────────────┐
│  [Company Logo]                                            │
├────────────────────────────────────────────────────────────┤
│                                                            │
│   Invoice viewer                                           │
│   [Download PDF]                                           │
│                                                            │
└────────────────────────────────────────────────────────────┘
```

### Shared layout components (in `packages/ui`)

| Component | What it does |
|-----------|-------------|
| `AppShell` | Wraps sidebar + topbar + content area |
| `PageHeader` | Page title, breadcrumb trail, action buttons (e.g. "New Invoice") |
| `DataCard` | Stat card used on dashboards (number + label + trend) |
| `EmptyState` | Shown when a table/list has no data |
| `ConfirmDialog` | "Are you sure?" modal — used before deletes/destructive actions |
| `LoadingSpinner` | Consistent loading indicator |
| `ErrorBoundary` | Catches React errors and shows a friendly message |

---

## 8. Data Fetching & State Management

### Two kinds of state

**Server state** is data that lives on the backend (invoices, users, organizations). It needs to be fetched, cached, and kept fresh. **TanStack Query** handles this.

**UI state** is data that only exists in the browser (is the sidebar open? which org is selected? is the user logged in?). **Zustand** handles this.

### TanStack Query — how it works

When you call `useZatcaInvoices()` in a component:
1. Query checks its cache — if data is less than 5 minutes old, returns it immediately (no network request)
2. If stale or missing, fetches from the API
3. While fetching, returns `{ isLoading: true }`
4. On success, caches the result and returns `{ data: [...] }`
5. On error, returns `{ isError: true, error: ... }`
6. When the user returns to the browser tab, automatically re-validates the data

**Global defaults:**
```
staleTime: 5 minutes       — data is "fresh" for 5 minutes
gcTime: 30 minutes         — unused cached data is kept for 30 minutes
retry: 1                   — retry failed requests once before showing error
refetchOnWindowFocus: true — re-check data when user returns to the tab
```

### Zustand auth store

The auth store is the single source of truth for who is logged in:

```ts
{
  token: string | null          // JWT token
  user: User | null             // Logged-in user's profile
  organization: Organization    // Currently selected organization
  organizations: Organization[] // All orgs this user can access (for switcher)

  setAuth(token, user, orgs)    // Called after successful login
  switchOrg(org)                // Called when user picks a different org
  logout()                      // Clears everything, redirects to login
}
```

### Axios instance

One shared Axios instance in `packages/api-client`. It is configured once and used everywhere:

- **Base URL** comes from the `VITE_API_URL` environment variable
- **Request interceptor** — before every request, attaches:
  - `Authorization: Bearer <token>` from Zustand store
  - `X-Organization-Id: <org-id>` from Zustand store
- **Response interceptor (401)** — if the backend says the token is expired:
  1. Pauses all other requests
  2. Requests a new token from `/api/v1/auth/refresh`
  3. Retries the original failed request with the new token
  4. If refresh fails → calls `logout()` → user is sent to login page
- **Response interceptor (422)** — Laravel validation errors look like:
  ```json
  { "errors": { "invoice_number": ["The invoice number field is required."] } }
  ```
  The interceptor transforms these into a flat map `{ invoice_number: "The invoice number field is required." }` that React Hook Form can use directly to show errors under the right fields.

### Forms

All forms use **React Hook Form** with **Zod** validation:

```
User fills form
  → React Hook Form tracks field values
  → On submit, Zod schema validates all fields client-side
  → If invalid: show errors immediately (no network request)
  → If valid: send to API
  → If API returns 422: map errors back to fields
  → If API returns success: show success toast, reset or navigate
```

Zod schemas live in `packages/types` and match the Laravel Form Request validation rules exactly.

---

## 9. Testing Strategy

### Three layers

**Unit tests (Vitest)** — test individual functions and components in isolation:
- All `packages/ui` components (does the button render? does the badge show the right color?)
- Zustand store logic (does `switchOrg` clear the right state?)
- Zod schemas (does the invoice form reject missing fields?)
- API client hook logic (with MSW mocking the backend)

**Integration tests (Vitest + Testing Library)** — test full pages with mocked API:
- ZATCA onboarding wizard: can the user complete all 3 steps?
- Invoice form: does a 422 error show the right message under the right field?
- Permission gating: is the Manufacturing menu hidden for an Accounting-only user?

**End-to-end tests (Playwright)** — test real browser flows:
- Login → select org → reach ZATCA dashboard
- Complete ZATCA onboarding wizard
- Submit an invoice → see it become "Cleared"
- Open vendor portal link → view invoice → download PDF

### MSW (Mock Service Worker)

MSW intercepts actual HTTP requests in the browser or test environment and returns fake responses. This means:
- You can develop the frontend without the backend running
- Tests run fast (no real network calls)
- All three test layers use the same mock data

Mock handlers live in `packages/api-client/src/mocks/` and mirror the real API response shapes exactly.

### Storybook

Every shared component in `packages/ui` has a Storybook story. This serves as:
- **Documentation** — see all component variants in one place
- **Development sandbox** — build components without running the full app
- **Visual baseline** — catch unintended visual regressions

### CI pipeline

Every pull request runs:
```
1. pnpm install          — install dependencies
2. turbo typecheck       — TypeScript type checking across all packages
3. turbo test            — Vitest unit + integration tests
4. turbo build           — production build (catches build-time errors)
5. playwright e2e        — end-to-end tests against built apps
```

---

## 10. Development & Deployment

### Running locally

**Prerequisites:**
- Node.js 20+
- pnpm (`npm install -g pnpm`)
- The backend running at `http://localhost:8000`

**Start all three apps at once:**
```bash
cd c:\laragon\www\erp-frontend
pnpm install
pnpm dev
```

Turborepo starts all apps in parallel:
- Staff app → http://localhost:5173
- Admin app → http://localhost:5174
- Vendor portal → http://localhost:5175

**Start only one app:**
```bash
pnpm --filter staff dev
```

### Environment variables

Each app has its own `.env.local` file (not committed to git):

```bash
# apps/staff/.env.local
VITE_API_URL=http://localhost:8000/api/v1
VITE_APP_NAME=ERP

# apps/admin/.env.local
VITE_API_URL=http://localhost:8000/api/v1
VITE_APP_NAME=ERP Admin

# apps/portal/.env.local
VITE_API_URL=http://localhost:8000/api/v1
VITE_APP_NAME=ERP Portal
```

In production, replace `localhost:8000` with the live backend URL.

### Building for production

```bash
pnpm build
```

Turborepo builds all apps. Output:
```
apps/staff/dist/     → static files for the staff app
apps/admin/dist/     → static files for the admin app
apps/portal/dist/    → static files for the vendor portal
```

These are plain HTML/CSS/JS files — they can be served by any web server.

### Deployment options

**Option 1: Nginx on same server as Laravel (recommended for staging)**

Serve the built files alongside the backend. No separate hosting needed.

```nginx
# Staff app
server {
    listen 80;
    server_name erp.yourdomain.com;
    root /laragon/www/erp-frontend/apps/staff/dist;
    index index.html;

    # All routes go to index.html (React handles routing)
    location / {
        try_files $uri $uri/ /index.html;
    }

    # Proxy API calls to Laravel
    location /api/ {
        proxy_pass http://localhost:8000;
        proxy_set_header Host $host;
    }
}
```

Repeat for admin and portal with different `server_name` and `root` values.

**Option 2: Vercel or Netlify (recommended for quick cloud deploy)**

- Connect the `erp-frontend` repo to Vercel
- Set `apps/staff` as the root directory
- Set `VITE_API_URL` as an environment variable in the Vercel dashboard
- Deploy — Vercel handles CDN, HTTPS, and automatic deploys on push

**Option 3: Docker (recommended for production)**

Each app gets a small Nginx Docker container:
```dockerfile
FROM node:20-alpine AS build
WORKDIR /app
COPY . .
RUN pnpm install && pnpm --filter staff build

FROM nginx:alpine
COPY --from=build /app/apps/staff/dist /usr/share/nginx/html
```

---

## 11. Getting Started — Step by Step

This section walks through creating the project from scratch.

### Step 1: Install prerequisites

```bash
# Install Node.js 20+ from https://nodejs.org

# Install pnpm globally
npm install -g pnpm

# Verify
node --version   # should be 20+
pnpm --version   # should be 8+
```

### Step 2: Create the monorepo

```bash
cd c:\laragon\www
mkdir erp-frontend
cd erp-frontend

# Initialize Turborepo
pnpm dlx create-turbo@latest . --package-manager pnpm
```

### Step 3: Create the three apps

```bash
# Inside erp-frontend/
pnpm dlx create-vite apps/staff --template react-ts
pnpm dlx create-vite apps/admin --template react-ts
pnpm dlx create-vite apps/portal --template react-ts
```

### Step 4: Create shared packages

```bash
mkdir -p packages/ui/src
mkdir -p packages/api-client/src
mkdir -p packages/types/src
```

Each package needs a `package.json` with a name like `@erp/ui`, `@erp/api-client`, `@erp/types`.

### Step 5: Install dependencies

```bash
# In root — Turborepo tooling
pnpm add -D turbo -w

# In each app
pnpm --filter staff add @tanstack/react-router @tanstack/react-query zustand axios react-hook-form zod ag-grid-react ag-grid-community

# shadcn/ui (run inside apps/staff)
pnpm dlx shadcn@latest init

# Dev dependencies
pnpm --filter staff add -D vitest @testing-library/react msw playwright
```

### Step 6: Configure Turborepo

`turbo.json` at the root defines the build pipeline:
```json
{
  "$schema": "https://turbo.build/schema.json",
  "tasks": {
    "build": { "dependsOn": ["^build"], "outputs": ["dist/**"] },
    "dev": { "cache": false, "persistent": true },
    "test": { "dependsOn": ["^build"] },
    "typecheck": {}
  }
}
```

### Step 7: Run the dev server

```bash
pnpm dev
# All three apps start simultaneously
```

### Step 8: Build the ZATCA module first

Follow the ZATCA screen map in Section 6. Start with:
1. The onboarding dashboard (simplest — just displays status)
2. The CCSID request form
3. The invoice AG Grid table
4. The invoice create form
5. The compliance report dashboard

---

## Appendix: File naming conventions

| Type | Convention | Example |
|------|------------|---------|
| Components | PascalCase | `ZatcaStatusBadge.tsx` |
| Hooks | camelCase with `use` prefix | `useZatcaInvoices.ts` |
| Pages/routes | kebab-case folder | `invoices/index.tsx` |
| Types/interfaces | PascalCase | `ZatcaInvoice.ts` |
| Test files | same name + `.test` | `ZatcaStatusBadge.test.tsx` |
| Story files | same name + `.stories` | `ZatcaStatusBadge.stories.tsx` |

## Appendix: API response envelope

All API responses from the backend follow this shape:

```ts
// Success
{
  "success": true,
  "message": "Success",
  "data": { ... },
  "meta": { "request_id": "uuid", "timestamp": "2026-05-02T10:00:00Z" }
}

// Error
{
  "success": false,
  "error": { "code": "VALIDATION_ERROR", "message": "Human-readable message" },
  "meta": { ... }
}

// Paginated list
{
  "success": true,
  "data": [ ... ],
  "meta": {
    "current_page": 1,
    "per_page": 20,
    "total": 450,
    "last_page": 23
  },
  "links": { "first": "...", "last": "...", "prev": null, "next": "..." }
}
```

The API client in `packages/api-client` unwraps these envelopes automatically so components only see the `data` portion.
