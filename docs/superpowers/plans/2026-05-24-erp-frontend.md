# ERP Frontend Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [x]`) syntax for tracking.

**Goal:** Build a Turborepo monorepo at `c:\laragon\www\erp-frontend` with three React + TypeScript apps (staff, admin, portal) and the full ZATCA compliance module as the first feature.

**Architecture:** Turborepo monorepo with pnpm workspaces. Three Vite apps share code through three packages: `@erp/types` (TypeScript interfaces), `@erp/api-client` (Axios + TanStack Query hooks), and `@erp/ui` (shadcn/ui components). Staff app uses TanStack Router for routing and Zustand for auth state.

**Tech Stack:** React 19, TypeScript, Vite, Turborepo, pnpm, TanStack Router, TanStack Query, Zustand, shadcn/ui, Tailwind v4, AG Grid Community, React Hook Form, Zod, Axios, MSW, Vitest, Playwright

---

## File Map

```
erp-frontend/
├── apps/
│   ├── staff/
│   │   ├── src/
│   │   │   ├── main.tsx
│   │   │   ├── router.tsx               # TanStack Router root
│   │   │   ├── routes/
│   │   │   │   ├── __root.tsx           # Root layout (AppShell)
│   │   │   │   ├── login.tsx
│   │   │   │   ├── org-picker.tsx
│   │   │   │   ├── app/
│   │   │   │   │   ├── route.tsx        # Protected route guard
│   │   │   │   │   ├── dashboard.tsx
│   │   │   │   │   └── compliance/
│   │   │   │   │       └── zatca/
│   │   │   │   │           ├── route.tsx
│   │   │   │   │           ├── onboarding/
│   │   │   │   │           │   ├── index.tsx
│   │   │   │   │           │   ├── ccsid.tsx
│   │   │   │   │           │   └── pcsid.tsx
│   │   │   │   │           ├── invoices/
│   │   │   │   │           │   ├── index.tsx
│   │   │   │   │           │   ├── $invoiceId.tsx
│   │   │   │   │           │   └── create.tsx
│   │   │   │   │           └── reports/
│   │   │   │   │               └── index.tsx
│   │   │   ├── store/
│   │   │   │   └── auth.ts              # Zustand auth store
│   │   │   └── test/
│   │   │       ├── setup.ts
│   │   │       └── mocks/
│   │   │           └── handlers.ts      # MSW handlers for staff app
│   │   ├── vite.config.ts
│   │   ├── tsconfig.json
│   │   └── package.json
│   ├── admin/
│   │   └── src/main.tsx                 # Minimal for now
│   └── portal/
│       └── src/main.tsx                 # Minimal for now
├── packages/
│   ├── types/
│   │   ├── src/
│   │   │   ├── index.ts
│   │   │   ├── core.ts                  # User, Organization, Branch, Role
│   │   │   └── zatca.ts                 # ZATCA-specific types
│   │   ├── tsconfig.json
│   │   └── package.json
│   ├── api-client/
│   │   ├── src/
│   │   │   ├── index.ts
│   │   │   ├── axios.ts                 # Axios instance + interceptors
│   │   │   ├── query-client.ts          # TanStack QueryClient config
│   │   │   ├── zatca.ts                 # ZATCA TanStack Query hooks
│   │   │   └── mocks/
│   │   │       ├── index.ts
│   │   │       └── zatca.ts             # MSW handlers for ZATCA
│   │   ├── tsconfig.json
│   │   └── package.json
│   └── ui/
│       ├── src/
│       │   ├── index.ts
│       │   ├── components/
│       │   │   ├── AppShell.tsx
│       │   │   ├── Sidebar.tsx
│       │   │   ├── TopBar.tsx
│       │   │   ├── PageHeader.tsx
│       │   │   ├── DataCard.tsx
│       │   │   ├── EmptyState.tsx
│       │   │   ├── ConfirmDialog.tsx
│       │   │   ├── LoadingSpinner.tsx
│       │   │   └── zatca/
│       │   │       ├── ZatcaStatusBadge.tsx
│       │   │       ├── ZatcaOnboardingWizard.tsx
│       │   │       ├── InvoiceXmlPreview.tsx
│       │   │       └── ComplianceStatsCard.tsx
│       ├── tsconfig.json
│       └── package.json
├── turbo.json
├── pnpm-workspace.yaml
└── package.json
```

---

## Phase 1: Monorepo Scaffold

### Task 1: Initialize the monorepo

**Files:**
- Create: `c:\laragon\www\erp-frontend\package.json`
- Create: `c:\laragon\www\erp-frontend\pnpm-workspace.yaml`
- Create: `c:\laragon\www\erp-frontend\turbo.json`
- Create: `c:\laragon\www\erp-frontend\.gitignore`

- [x] **Step 1: Create the root directory and initialize git**

```bash
cd c:\laragon\www
mkdir erp-frontend
cd erp-frontend
git init
```

- [x] **Step 2: Create root `package.json`**

```json
{
  "name": "erp-frontend",
  "private": true,
  "scripts": {
    "dev": "turbo dev",
    "build": "turbo build",
    "test": "turbo test",
    "typecheck": "turbo typecheck",
    "lint": "turbo lint"
  },
  "devDependencies": {
    "turbo": "^2.0.0",
    "typescript": "^5.4.0"
  },
  "engines": {
    "node": ">=20",
    "pnpm": ">=9"
  }
}
```

- [x] **Step 3: Create `pnpm-workspace.yaml`**

```yaml
packages:
  - "apps/*"
  - "packages/*"
```

- [x] **Step 4: Create `turbo.json`**

```json
{
  "$schema": "https://turbo.build/schema.json",
  "tasks": {
    "build": {
      "dependsOn": ["^build"],
      "outputs": ["dist/**"]
    },
    "dev": {
      "cache": false,
      "persistent": true
    },
    "test": {
      "dependsOn": ["^build"]
    },
    "typecheck": {
      "dependsOn": ["^typecheck"]
    }
  }
}
```

- [x] **Step 5: Create `.gitignore`**

```
node_modules/
dist/
.turbo/
*.local
.env.local
.env.*.local
```

- [x] **Step 6: Install Turborepo**

```bash
pnpm install
```

Expected: `node_modules/.pnpm` created at root, `turbo` available.

- [x] **Step 7: Commit**

```bash
git add .
git commit -m "chore: initialize Turborepo monorepo"
```

---

### Task 2: Scaffold the three Vite apps

**Files:**
- Create: `apps/staff/` (full Vite + React TS app)
- Create: `apps/admin/` (minimal)
- Create: `apps/portal/` (minimal)

- [x] **Step 1: Create staff app**

```bash
pnpm dlx create-vite apps/staff --template react-ts
```

- [x] **Step 2: Create admin app**

```bash
pnpm dlx create-vite apps/admin --template react-ts
```

- [x] **Step 3: Create portal app**

```bash
pnpm dlx create-vite apps/portal --template react-ts
```

- [x] **Step 4: Update `apps/staff/package.json` — set name and add scripts**

Replace the generated `package.json` with:

```json
{
  "name": "@erp/staff",
  "private": true,
  "version": "0.0.1",
  "type": "module",
  "scripts": {
    "dev": "vite --port 5173",
    "build": "tsc -b && vite build",
    "test": "vitest run",
    "test:watch": "vitest",
    "typecheck": "tsc --noEmit",
    "preview": "vite preview"
  },
  "dependencies": {
    "@erp/api-client": "workspace:*",
    "@erp/types": "workspace:*",
    "@erp/ui": "workspace:*",
    "@tanstack/react-query": "^5.40.0",
    "@tanstack/react-router": "^1.40.0",
    "axios": "^1.7.0",
    "react": "^19.0.0",
    "react-dom": "^19.0.0",
    "zustand": "^5.0.0"
  },
  "devDependencies": {
    "@testing-library/react": "^16.0.0",
    "@testing-library/user-event": "^14.5.0",
    "@types/react": "^19.0.0",
    "@types/react-dom": "^19.0.0",
    "@vitejs/plugin-react": "^4.3.0",
    "jsdom": "^24.0.0",
    "msw": "^2.3.0",
    "typescript": "^5.4.0",
    "vite": "^5.3.0",
    "vitest": "^2.0.0"
  }
}
```

- [x] **Step 5: Repeat for `apps/admin/package.json`**

```json
{
  "name": "@erp/admin",
  "private": true,
  "version": "0.0.1",
  "type": "module",
  "scripts": {
    "dev": "vite --port 5174",
    "build": "tsc -b && vite build",
    "typecheck": "tsc --noEmit"
  },
  "dependencies": {
    "@erp/api-client": "workspace:*",
    "@erp/types": "workspace:*",
    "@erp/ui": "workspace:*",
    "react": "^19.0.0",
    "react-dom": "^19.0.0"
  },
  "devDependencies": {
    "@types/react": "^19.0.0",
    "@types/react-dom": "^19.0.0",
    "@vitejs/plugin-react": "^4.3.0",
    "typescript": "^5.4.0",
    "vite": "^5.3.0"
  }
}
```

- [x] **Step 6: Repeat for `apps/portal/package.json`**

```json
{
  "name": "@erp/portal",
  "private": true,
  "version": "0.0.1",
  "type": "module",
  "scripts": {
    "dev": "vite --port 5175",
    "build": "tsc -b && vite build",
    "typecheck": "tsc --noEmit"
  },
  "dependencies": {
    "@erp/types": "workspace:*",
    "react": "^19.0.0",
    "react-dom": "^19.0.0"
  },
  "devDependencies": {
    "@types/react": "^19.0.0",
    "@types/react-dom": "^19.0.0",
    "@vitejs/plugin-react": "^4.3.0",
    "typescript": "^5.4.0",
    "vite": "^5.3.0"
  }
}
```

- [x] **Step 7: Install all dependencies**

```bash
pnpm install
```

Expected: All three apps get their `node_modules` resolved.

- [x] **Step 8: Commit**

```bash
git add .
git commit -m "chore: scaffold three Vite apps (staff, admin, portal)"
```

---

### Task 3: Scaffold the three shared packages

**Files:**
- Create: `packages/types/package.json`
- Create: `packages/types/tsconfig.json`
- Create: `packages/types/src/index.ts`
- Create: `packages/api-client/package.json`
- Create: `packages/api-client/tsconfig.json`
- Create: `packages/api-client/src/index.ts`
- Create: `packages/ui/package.json`
- Create: `packages/ui/tsconfig.json`
- Create: `packages/ui/src/index.ts`

- [x] **Step 1: Create `packages/types/package.json`**

```json
{
  "name": "@erp/types",
  "version": "0.0.1",
  "private": true,
  "main": "./src/index.ts",
  "types": "./src/index.ts",
  "scripts": {
    "typecheck": "tsc --noEmit"
  },
  "devDependencies": {
    "typescript": "^5.4.0"
  }
}
```

- [x] **Step 2: Create `packages/types/tsconfig.json`**

```json
{
  "compilerOptions": {
    "target": "ES2022",
    "module": "ESNext",
    "moduleResolution": "bundler",
    "strict": true,
    "skipLibCheck": true
  },
  "include": ["src"]
}
```

- [x] **Step 3: Create `packages/types/src/index.ts`** (empty barrel, filled in Task 5)

```ts
export * from './core'
export * from './zatca'
```

- [x] **Step 4: Create `packages/api-client/package.json`**

```json
{
  "name": "@erp/api-client",
  "version": "0.0.1",
  "private": true,
  "main": "./src/index.ts",
  "types": "./src/index.ts",
  "scripts": {
    "typecheck": "tsc --noEmit"
  },
  "dependencies": {
    "@erp/types": "workspace:*",
    "@tanstack/react-query": "^5.40.0",
    "axios": "^1.7.0"
  },
  "devDependencies": {
    "msw": "^2.3.0",
    "typescript": "^5.4.0"
  }
}
```

- [x] **Step 5: Create `packages/api-client/tsconfig.json`**

```json
{
  "compilerOptions": {
    "target": "ES2022",
    "module": "ESNext",
    "moduleResolution": "bundler",
    "strict": true,
    "skipLibCheck": true,
    "jsx": "react-jsx"
  },
  "include": ["src"]
}
```

- [x] **Step 6: Create `packages/api-client/src/index.ts`** (barrel, filled later)

```ts
export * from './axios'
export * from './query-client'
export * from './zatca'
```

- [x] **Step 7: Create `packages/ui/package.json`**

```json
{
  "name": "@erp/ui",
  "version": "0.0.1",
  "private": true,
  "main": "./src/index.ts",
  "types": "./src/index.ts",
  "scripts": {
    "typecheck": "tsc --noEmit"
  },
  "dependencies": {
    "@erp/types": "workspace:*",
    "react": "^19.0.0",
    "react-dom": "^19.0.0"
  },
  "peerDependencies": {
    "react": "^19.0.0",
    "react-dom": "^19.0.0"
  },
  "devDependencies": {
    "@types/react": "^19.0.0",
    "@types/react-dom": "^19.0.0",
    "typescript": "^5.4.0"
  }
}
```

- [x] **Step 8: Create `packages/ui/tsconfig.json`**

```json
{
  "compilerOptions": {
    "target": "ES2022",
    "module": "ESNext",
    "moduleResolution": "bundler",
    "strict": true,
    "skipLibCheck": true,
    "jsx": "react-jsx"
  },
  "include": ["src"]
}
```

- [x] **Step 9: Create `packages/ui/src/index.ts`** (barrel, filled later)

```ts
export * from './components/AppShell'
export * from './components/Sidebar'
export * from './components/TopBar'
export * from './components/PageHeader'
export * from './components/DataCard'
export * from './components/EmptyState'
export * from './components/ConfirmDialog'
export * from './components/LoadingSpinner'
export * from './components/zatca/ZatcaStatusBadge'
export * from './components/zatca/ZatcaOnboardingWizard'
export * from './components/zatca/InvoiceXmlPreview'
export * from './components/zatca/ComplianceStatsCard'
```

- [x] **Step 10: Install and verify typecheck runs**

```bash
pnpm install
pnpm typecheck
```

Expected: No errors (files are empty stubs).

- [x] **Step 11: Commit**

```bash
git add .
git commit -m "chore: scaffold shared packages (types, api-client, ui)"
```

---

## Phase 2: Shared Package Types

### Task 4: Core TypeScript types

**Files:**
- Create: `packages/types/src/core.ts`
- Create: `packages/types/src/zatca.ts`

- [x] **Step 1: Create `packages/types/src/core.ts`**

```ts
export interface Organization {
  id: string
  name: string
  tax_number: string
  country: 'SA' | 'AE' | 'BH' | 'OM' | 'QA' | 'KW' | 'IN'
  currency: string
  is_active: boolean
}

export interface Branch {
  id: string
  organization_id: string
  name: string
  code: string
  is_active: boolean
}

export interface User {
  id: string
  name: string
  email: string
  organization_id: string
  roles: Role[]
  permissions: string[]
}

export interface Role {
  id: string
  name: string
  slug: string
}

export interface ApiResponse<T> {
  success: boolean
  message: string
  data: T
  meta: {
    request_id: string
    timestamp: string
  }
}

export interface PaginatedResponse<T> {
  success: boolean
  data: T[]
  meta: {
    current_page: number
    per_page: number
    total: number
    last_page: number
    request_id: string
    timestamp: string
  }
  links: {
    first: string | null
    last: string | null
    prev: string | null
    next: string | null
  }
}

export interface ApiError {
  success: false
  error: {
    code: string
    message: string
  }
  meta: {
    request_id: string
    timestamp: string
  }
}

export interface ValidationErrors {
  [field: string]: string
}
```

- [x] **Step 2: Create `packages/types/src/zatca.ts`**

```ts
export type ZatcaOnboardingStatus =
  | 'not_started'
  | 'csr_generated'
  | 'ccsid_requested'
  | 'compliance_check_passed'
  | 'pcsid_active'
  | 'failed'

export interface ZatcaDeviceOnboarding {
  branch_id: string
  branch_name: string
  status: ZatcaOnboardingStatus
  ccsid_expires_at: string | null
  pcsid_issued_at: string | null
  last_error: string | null
}

export type ZatcaInvoiceStatus = 'pending' | 'submitted' | 'cleared' | 'rejected'

export interface ZatcaInvoice {
  id: string
  invoice_number: string
  invoice_type: 'standard' | 'simplified'
  buyer_name: string
  buyer_vat: string | null
  total_amount: number
  vat_amount: number
  currency: string
  status: ZatcaInvoiceStatus
  zatca_uuid: string | null
  zatca_hash: string | null
  rejection_reason: string | null
  submitted_at: string | null
  cleared_at: string | null
  created_at: string
}

export interface ZatcaInvoiceDetail extends ZatcaInvoice {
  xml_content: string
  line_items: ZatcaLineItem[]
}

export interface ZatcaLineItem {
  description: string
  quantity: number
  unit_price: number
  vat_rate: number
  vat_amount: number
  total: number
}

export interface ZatcaComplianceReport {
  period_start: string
  period_end: string
  total_submitted: number
  total_cleared: number
  total_rejected: number
  total_pending: number
  clearance_rate: number
  rejection_rate: number
  last_submission_at: string | null
}

export interface CreateZatcaInvoicePayload {
  buyer_name: string
  buyer_vat: string
  invoice_type: 'standard' | 'simplified'
  currency: string
  line_items: {
    description: string
    quantity: number
    unit_price: number
    vat_rate: number
  }[]
}
```

- [x] **Step 3: Run typecheck**

```bash
pnpm --filter @erp/types typecheck
```

Expected: No errors.

- [x] **Step 4: Commit**

```bash
git add packages/types/
git commit -m "feat(types): add core and ZATCA TypeScript interfaces"
```

---

## Phase 3: API Client

### Task 5: Axios instance and QueryClient

**Files:**
- Create: `packages/api-client/src/axios.ts`
- Create: `packages/api-client/src/query-client.ts`

- [x] **Step 1: Write failing test for axios instance**

Create `packages/api-client/src/axios.test.ts`:

```ts
import { describe, it, expect, vi, beforeEach } from 'vitest'
import { createApiClient } from './axios'

describe('createApiClient', () => {
  it('sets Authorization header when token provided', async () => {
    const client = createApiClient('http://localhost:8000/api/v1')
    client.interceptors.request.handlers = []

    // Add token getter
    const getToken = vi.fn().mockReturnValue('test-token')
    const getOrgId = vi.fn().mockReturnValue('org-123')
    const instance = createApiClient('http://localhost:8000/api/v1', getToken, getOrgId)

    // Inspect interceptors were added
    expect(instance.defaults.baseURL).toBe('http://localhost:8000/api/v1')
    expect(instance.interceptors.request.handlers.length).toBeGreaterThan(0)
  })

  it('has correct default headers', () => {
    const instance = createApiClient('http://localhost:8000/api/v1')
    expect(instance.defaults.headers['Content-Type']).toBe('application/json')
    expect(instance.defaults.headers['Accept']).toBe('application/json')
  })
})
```

- [x] **Step 2: Run test to verify it fails**

```bash
pnpm --filter @erp/api-client test
```

Expected: FAIL — `createApiClient` not found.

- [x] **Step 3: Create `packages/api-client/src/axios.ts`**

```ts
import axios, { AxiosInstance, InternalAxiosRequestConfig, AxiosError } from 'axios'
import type { ValidationErrors } from '@erp/types'

export type TokenGetter = () => string | null
export type OrgIdGetter = () => string | null
export type LogoutFn = () => void

export function createApiClient(
  baseURL: string,
  getToken?: TokenGetter,
  getOrgId?: OrgIdGetter,
  onLogout?: LogoutFn,
): AxiosInstance {
  const instance = axios.create({
    baseURL,
    headers: {
      'Content-Type': 'application/json',
      Accept: 'application/json',
    },
  })

  // Attach JWT + org ID to every request
  instance.interceptors.request.use((config: InternalAxiosRequestConfig) => {
    const token = getToken?.()
    const orgId = getOrgId?.()
    if (token) config.headers.Authorization = `Bearer ${token}`
    if (orgId) config.headers['X-Organization-Id'] = orgId
    return config
  })

  // Normalize 422 validation errors from Laravel
  instance.interceptors.response.use(
    (response) => response,
    (error: AxiosError<{ errors?: Record<string, string[]> }>) => {
      if (error.response?.status === 422 && error.response.data?.errors) {
        const flat: ValidationErrors = {}
        for (const [field, messages] of Object.entries(error.response.data.errors)) {
          flat[field] = messages[0]
        }
        return Promise.reject({ ...error, validationErrors: flat })
      }
      return Promise.reject(error)
    },
  )

  return instance
}

// Singleton instance — configured at app startup with store getters
let _client: AxiosInstance | null = null

export function initApiClient(
  baseURL: string,
  getToken: TokenGetter,
  getOrgId: OrgIdGetter,
  onLogout: LogoutFn,
): AxiosInstance {
  _client = createApiClient(baseURL, getToken, getOrgId, onLogout)

  // 401 token refresh logic
  let isRefreshing = false
  let queue: Array<(token: string) => void> = []

  _client.interceptors.response.use(
    (r) => r,
    async (error: AxiosError) => {
      const original = error.config as InternalAxiosRequestConfig & { _retry?: boolean }
      if (error.response?.status === 401 && !original._retry) {
        if (isRefreshing) {
          return new Promise((resolve) => {
            queue.push((token) => {
              original.headers.Authorization = `Bearer ${token}`
              resolve(_client!.request(original))
            })
          })
        }
        original._retry = true
        isRefreshing = true
        try {
          const { data } = await _client!.post<{ data: { token: string } }>('/auth/refresh')
          const newToken = data.data.token
          queue.forEach((cb) => cb(newToken))
          queue = []
          original.headers.Authorization = `Bearer ${newToken}`
          return _client!.request(original)
        } catch {
          onLogout()
          return Promise.reject(error)
        } finally {
          isRefreshing = false
        }
      }
      return Promise.reject(error)
    },
  )

  return _client
}

export function getApiClient(): AxiosInstance {
  if (!_client) throw new Error('API client not initialized. Call initApiClient first.')
  return _client
}
```

- [x] **Step 4: Create `packages/api-client/src/query-client.ts`**

```ts
import { QueryClient } from '@tanstack/react-query'

export function createQueryClient(): QueryClient {
  return new QueryClient({
    defaultOptions: {
      queries: {
        staleTime: 1000 * 60 * 5,      // 5 minutes
        gcTime: 1000 * 60 * 30,         // 30 minutes
        retry: 1,
        refetchOnWindowFocus: true,
      },
    },
  })
}
```

- [x] **Step 5: Run tests to verify they pass**

```bash
pnpm --filter @erp/api-client test
```

Expected: PASS.

- [x] **Step 6: Commit**

```bash
git add packages/api-client/src/axios.ts packages/api-client/src/query-client.ts packages/api-client/src/axios.test.ts
git commit -m "feat(api-client): add Axios instance with JWT interceptors and QueryClient"
```

---

### Task 6: ZATCA API hooks

**Files:**
- Create: `packages/api-client/src/zatca.ts`
- Create: `packages/api-client/src/mocks/zatca.ts`

- [x] **Step 1: Create `packages/api-client/src/zatca.ts`**

```ts
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import type {
  ZatcaDeviceOnboarding,
  ZatcaInvoice,
  ZatcaInvoiceDetail,
  ZatcaComplianceReport,
  CreateZatcaInvoicePayload,
  ApiResponse,
  PaginatedResponse,
} from '@erp/types'
import { getApiClient } from './axios'

// Query keys
export const zatcaKeys = {
  onboarding: (branchId: string) => ['zatca', 'onboarding', branchId] as const,
  invoices: (filters: Record<string, unknown>) => ['zatca', 'invoices', filters] as const,
  invoice: (id: string) => ['zatca', 'invoice', id] as const,
  report: (dateRange: { start: string; end: string }) => ['zatca', 'report', dateRange] as const,
}

export function useZatcaOnboardingStatus(branchId: string) {
  return useQuery({
    queryKey: zatcaKeys.onboarding(branchId),
    queryFn: async () => {
      const { data } = await getApiClient().get<ApiResponse<ZatcaDeviceOnboarding>>(
        `/compliance/branches/${branchId}/onboarding`,
      )
      return data.data
    },
  })
}

export function useRequestCcsid(branchId: string) {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: async () => {
      const { data } = await getApiClient().post<ApiResponse<ZatcaDeviceOnboarding>>(
        `/compliance/branches/${branchId}/ccsid`,
      )
      return data.data
    },
    onSuccess: () => qc.invalidateQueries({ queryKey: zatcaKeys.onboarding(branchId) }),
  })
}

export function useUpgradeToPcsid(branchId: string) {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: async () => {
      const { data } = await getApiClient().post<ApiResponse<ZatcaDeviceOnboarding>>(
        `/compliance/branches/${branchId}/pcsid`,
      )
      return data.data
    },
    onSuccess: () => qc.invalidateQueries({ queryKey: zatcaKeys.onboarding(branchId) }),
  })
}

export interface ZatcaInvoiceFilters {
  page?: number
  per_page?: number
  status?: string
  date_from?: string
  date_to?: string
}

export function useZatcaInvoices(filters: ZatcaInvoiceFilters = {}) {
  return useQuery({
    queryKey: zatcaKeys.invoices(filters),
    queryFn: async () => {
      const { data } = await getApiClient().get<PaginatedResponse<ZatcaInvoice>>(
        '/compliance/zatca/invoices',
        { params: filters },
      )
      return data
    },
  })
}

export function useZatcaInvoice(invoiceId: string) {
  return useQuery({
    queryKey: zatcaKeys.invoice(invoiceId),
    queryFn: async () => {
      const { data } = await getApiClient().get<ApiResponse<ZatcaInvoiceDetail>>(
        `/compliance/zatca/invoices/${invoiceId}`,
      )
      return data.data
    },
  })
}

export function useCreateZatcaInvoice() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: async (payload: CreateZatcaInvoicePayload) => {
      const { data } = await getApiClient().post<ApiResponse<ZatcaInvoice>>(
        '/compliance/zatca/invoices',
        payload,
      )
      return data.data
    },
    onSuccess: () => qc.invalidateQueries({ queryKey: ['zatca', 'invoices'] }),
  })
}

export function useSubmitInvoice() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: async (invoiceId: string) => {
      const { data } = await getApiClient().post<ApiResponse<ZatcaInvoice>>(
        `/compliance/zatca/invoices/${invoiceId}/submit`,
      )
      return data.data
    },
    onSuccess: (_, invoiceId) => {
      qc.invalidateQueries({ queryKey: ['zatca', 'invoices'] })
      qc.invalidateQueries({ queryKey: zatcaKeys.invoice(invoiceId) })
    },
  })
}

export function useRetryInvoice() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: async (invoiceId: string) => {
      const { data } = await getApiClient().post<ApiResponse<ZatcaInvoice>>(
        `/compliance/zatca/invoices/${invoiceId}/retry`,
      )
      return data.data
    },
    onSuccess: (_, invoiceId) => {
      qc.invalidateQueries({ queryKey: ['zatca', 'invoices'] })
      qc.invalidateQueries({ queryKey: zatcaKeys.invoice(invoiceId) })
    },
  })
}

export function useComplianceReport(dateRange: { start: string; end: string }) {
  return useQuery({
    queryKey: zatcaKeys.report(dateRange),
    queryFn: async () => {
      const { data } = await getApiClient().get<ApiResponse<ZatcaComplianceReport>>(
        '/compliance/zatca/report',
        { params: dateRange },
      )
      return data.data
    },
  })
}
```

- [x] **Step 2: Create MSW mock handlers `packages/api-client/src/mocks/zatca.ts`**

```ts
import { http, HttpResponse } from 'msw'
import type { ZatcaDeviceOnboarding, ZatcaInvoice, ZatcaComplianceReport } from '@erp/types'

const BASE = '/api/v1'

export const zatcaMockHandlers = [
  http.get(`${BASE}/compliance/branches/:branchId/onboarding`, ({ params }) => {
    const onboarding: ZatcaDeviceOnboarding = {
      branch_id: params.branchId as string,
      branch_name: 'Main Branch',
      status: 'not_started',
      ccsid_expires_at: null,
      pcsid_issued_at: null,
      last_error: null,
    }
    return HttpResponse.json({ success: true, data: onboarding, meta: {} })
  }),

  http.post(`${BASE}/compliance/branches/:branchId/ccsid`, ({ params }) => {
    const onboarding: ZatcaDeviceOnboarding = {
      branch_id: params.branchId as string,
      branch_name: 'Main Branch',
      status: 'ccsid_requested',
      ccsid_expires_at: new Date(Date.now() + 365 * 24 * 60 * 60 * 1000).toISOString(),
      pcsid_issued_at: null,
      last_error: null,
    }
    return HttpResponse.json({ success: true, data: onboarding, meta: {} })
  }),

  http.post(`${BASE}/compliance/branches/:branchId/pcsid`, ({ params }) => {
    const onboarding: ZatcaDeviceOnboarding = {
      branch_id: params.branchId as string,
      branch_name: 'Main Branch',
      status: 'pcsid_active',
      ccsid_expires_at: new Date(Date.now() + 365 * 24 * 60 * 60 * 1000).toISOString(),
      pcsid_issued_at: new Date().toISOString(),
      last_error: null,
    }
    return HttpResponse.json({ success: true, data: onboarding, meta: {} })
  }),

  http.get(`${BASE}/compliance/zatca/invoices`, () => {
    const invoices: ZatcaInvoice[] = [
      {
        id: '1',
        invoice_number: 'INV-001',
        invoice_type: 'standard',
        buyer_name: 'Acme Corp',
        buyer_vat: '300000000000003',
        total_amount: 1150,
        vat_amount: 150,
        currency: 'SAR',
        status: 'cleared',
        zatca_uuid: 'abc-uuid',
        zatca_hash: 'abc-hash',
        rejection_reason: null,
        submitted_at: new Date().toISOString(),
        cleared_at: new Date().toISOString(),
        created_at: new Date().toISOString(),
      },
    ]
    return HttpResponse.json({
      success: true,
      data: invoices,
      meta: { current_page: 1, per_page: 20, total: 1, last_page: 1 },
    })
  }),

  http.get(`${BASE}/compliance/zatca/report`, () => {
    const report: ZatcaComplianceReport = {
      period_start: '2026-05-01',
      period_end: '2026-05-31',
      total_submitted: 42,
      total_cleared: 38,
      total_rejected: 2,
      total_pending: 2,
      clearance_rate: 90.5,
      rejection_rate: 4.8,
      last_submission_at: new Date().toISOString(),
    }
    return HttpResponse.json({ success: true, data: report, meta: {} })
  }),
]
```

- [x] **Step 3: Create `packages/api-client/src/mocks/index.ts`**

```ts
export { zatcaMockHandlers } from './zatca'

export const allMockHandlers = [...zatcaMockHandlers]
```

- [x] **Step 4: Typecheck**

```bash
pnpm --filter @erp/api-client typecheck
```

Expected: No errors.

- [x] **Step 5: Commit**

```bash
git add packages/api-client/
git commit -m "feat(api-client): add ZATCA TanStack Query hooks and MSW mock handlers"
```

---

## Phase 4: Auth

### Task 7: Zustand auth store

**Files:**
- Create: `apps/staff/src/store/auth.ts`
- Create: `apps/staff/src/store/auth.test.ts`

- [x] **Step 1: Write failing test**

Create `apps/staff/src/store/auth.test.ts`:

```ts
import { describe, it, expect, beforeEach } from 'vitest'
import { useAuthStore } from './auth'

describe('useAuthStore', () => {
  beforeEach(() => {
    useAuthStore.setState({
      token: null,
      user: null,
      organization: null,
      organizations: [],
    })
    localStorage.clear()
  })

  it('setAuth stores token and user', () => {
    const user = { id: '1', name: 'John', email: 'john@test.com', organization_id: 'org-1', roles: [], permissions: [] }
    const org = { id: 'org-1', name: 'Acme', tax_number: '123', country: 'SA' as const, currency: 'SAR', is_active: true }
    useAuthStore.getState().setAuth('tok-123', user, [org], org)
    expect(useAuthStore.getState().token).toBe('tok-123')
    expect(useAuthStore.getState().user?.email).toBe('john@test.com')
    expect(useAuthStore.getState().organization?.id).toBe('org-1')
    expect(localStorage.getItem('erp_token')).toBe('tok-123')
  })

  it('logout clears all state and localStorage', () => {
    localStorage.setItem('erp_token', 'tok-123')
    useAuthStore.setState({ token: 'tok-123' })
    useAuthStore.getState().logout()
    expect(useAuthStore.getState().token).toBeNull()
    expect(localStorage.getItem('erp_token')).toBeNull()
  })

  it('switchOrg updates selected organization', () => {
    const org1 = { id: 'org-1', name: 'Acme', tax_number: '123', country: 'SA' as const, currency: 'SAR', is_active: true }
    const org2 = { id: 'org-2', name: 'Beta', tax_number: '456', country: 'AE' as const, currency: 'AED', is_active: true }
    useAuthStore.setState({ organizations: [org1, org2], organization: org1 })
    useAuthStore.getState().switchOrg(org2)
    expect(useAuthStore.getState().organization?.id).toBe('org-2')
    expect(localStorage.getItem('erp_org_id')).toBe('org-2')
  })
})
```

- [x] **Step 2: Run test — verify it fails**

```bash
pnpm --filter @erp/staff test
```

Expected: FAIL — `useAuthStore` not found.

- [x] **Step 3: Create `apps/staff/src/store/auth.ts`**

```ts
import { create } from 'zustand'
import type { User, Organization } from '@erp/types'

interface AuthState {
  token: string | null
  user: User | null
  organization: Organization | null
  organizations: Organization[]
  setAuth: (token: string, user: User, orgs: Organization[], selectedOrg: Organization) => void
  switchOrg: (org: Organization) => void
  logout: () => void
  hydrateFromStorage: () => void
}

export const useAuthStore = create<AuthState>((set) => ({
  token: null,
  user: null,
  organization: null,
  organizations: [],

  setAuth: (token, user, orgs, selectedOrg) => {
    localStorage.setItem('erp_token', token)
    localStorage.setItem('erp_org_id', selectedOrg.id)
    set({ token, user, organizations: orgs, organization: selectedOrg })
  },

  switchOrg: (org) => {
    localStorage.setItem('erp_org_id', org.id)
    set({ organization: org })
  },

  logout: () => {
    localStorage.removeItem('erp_token')
    localStorage.removeItem('erp_org_id')
    set({ token: null, user: null, organization: null, organizations: [] })
  },

  hydrateFromStorage: () => {
    const token = localStorage.getItem('erp_token')
    if (token) set({ token })
  },
}))
```

- [x] **Step 4: Configure Vitest in staff app**

Update `apps/staff/vite.config.ts`:

```ts
import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'

export default defineConfig({
  plugins: [react()],
  test: {
    globals: true,
    environment: 'jsdom',
    setupFiles: ['./src/test/setup.ts'],
  },
})
```

Create `apps/staff/src/test/setup.ts`:

```ts
import '@testing-library/jest-dom'
```

- [x] **Step 5: Run tests — verify they pass**

```bash
pnpm --filter @erp/staff test
```

Expected: 3 tests PASS.

- [x] **Step 6: Commit**

```bash
git add apps/staff/src/store/ apps/staff/vite.config.ts apps/staff/src/test/
git commit -m "feat(staff): add Zustand auth store with tests"
```

---

## Phase 5: Staff App Shell

### Task 8: Tailwind + shadcn/ui setup in packages/ui

**Files:**
- Modify: `packages/ui/package.json` (add shadcn deps)
- Create: `packages/ui/src/components/LoadingSpinner.tsx`
- Create: `packages/ui/src/components/EmptyState.tsx`

- [x] **Step 1: Install shadcn/ui dependencies in packages/ui**

```bash
pnpm --filter @erp/ui add clsx tailwind-merge class-variance-authority lucide-react
```

- [x] **Step 2: Create utility `packages/ui/src/lib/utils.ts`**

```ts
import { clsx, type ClassValue } from 'clsx'
import { twMerge } from 'tailwind-merge'

export function cn(...inputs: ClassValue[]) {
  return twMerge(clsx(inputs))
}
```

- [x] **Step 3: Create `packages/ui/src/components/LoadingSpinner.tsx`**

```tsx
import { cn } from '../lib/utils'

interface LoadingSpinnerProps {
  className?: string
  size?: 'sm' | 'md' | 'lg'
}

const sizes = { sm: 'h-4 w-4', md: 'h-8 w-8', lg: 'h-12 w-12' }

export function LoadingSpinner({ className, size = 'md' }: LoadingSpinnerProps) {
  return (
    <div
      role="status"
      aria-label="Loading"
      className={cn('animate-spin rounded-full border-2 border-gray-300 border-t-blue-600', sizes[size], className)}
    />
  )
}
```

- [x] **Step 4: Write test for LoadingSpinner**

Create `packages/ui/src/components/LoadingSpinner.test.tsx`:

```tsx
import { render, screen } from '@testing-library/react'
import { describe, it, expect } from 'vitest'
import { LoadingSpinner } from './LoadingSpinner'

describe('LoadingSpinner', () => {
  it('renders with accessible label', () => {
    render(<LoadingSpinner />)
    expect(screen.getByRole('status', { name: 'Loading' })).toBeInTheDocument()
  })

  it('applies size classes', () => {
    render(<LoadingSpinner size="lg" />)
    const el = screen.getByRole('status')
    expect(el.className).toContain('h-12')
  })
})
```

- [x] **Step 5: Create `packages/ui/src/components/EmptyState.tsx`**

```tsx
import { cn } from '../lib/utils'

interface EmptyStateProps {
  title: string
  description?: string
  action?: React.ReactNode
  className?: string
}

export function EmptyState({ title, description, action, className }: EmptyStateProps) {
  return (
    <div className={cn('flex flex-col items-center justify-center py-12 text-center', className)}>
      <div className="rounded-full bg-gray-100 p-4 mb-4">
        <svg className="h-8 w-8 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4" />
        </svg>
      </div>
      <h3 className="text-lg font-semibold text-gray-900">{title}</h3>
      {description && <p className="mt-1 text-sm text-gray-500">{description}</p>}
      {action && <div className="mt-4">{action}</div>}
    </div>
  )
}
```

- [x] **Step 6: Commit**

```bash
git add packages/ui/
git commit -m "feat(ui): add shadcn utils, LoadingSpinner, EmptyState with tests"
```

---

### Task 9: ZatcaStatusBadge component

**Files:**
- Create: `packages/ui/src/components/zatca/ZatcaStatusBadge.tsx`
- Create: `packages/ui/src/components/zatca/ZatcaStatusBadge.test.tsx`

- [x] **Step 1: Write failing test**

Create `packages/ui/src/components/zatca/ZatcaStatusBadge.test.tsx`:

```tsx
import { render, screen } from '@testing-library/react'
import { describe, it, expect } from 'vitest'
import { ZatcaStatusBadge } from './ZatcaStatusBadge'

describe('ZatcaStatusBadge', () => {
  it('renders "Cleared" for cleared status', () => {
    render(<ZatcaStatusBadge status="cleared" />)
    expect(screen.getByText('Cleared')).toBeInTheDocument()
  })

  it('renders "Rejected" in red for rejected status', () => {
    render(<ZatcaStatusBadge status="rejected" />)
    const badge = screen.getByText('Rejected')
    expect(badge.className).toContain('red')
  })

  it('renders "Pending" for pending status', () => {
    render(<ZatcaStatusBadge status="pending" />)
    expect(screen.getByText('Pending')).toBeInTheDocument()
  })

  it('renders "Submitted" for submitted status', () => {
    render(<ZatcaStatusBadge status="submitted" />)
    expect(screen.getByText('Submitted')).toBeInTheDocument()
  })
})
```

- [x] **Step 2: Run test — verify it fails**

```bash
pnpm --filter @erp/ui test
```

Expected: FAIL — component not found.

- [x] **Step 3: Create `packages/ui/src/components/zatca/ZatcaStatusBadge.tsx`**

```tsx
import { cn } from '../../lib/utils'
import type { ZatcaInvoiceStatus } from '@erp/types'

const statusConfig: Record<ZatcaInvoiceStatus, { label: string; className: string }> = {
  pending:   { label: 'Pending',   className: 'bg-gray-100 text-gray-700' },
  submitted: { label: 'Submitted', className: 'bg-blue-100 text-blue-700' },
  cleared:   { label: 'Cleared',   className: 'bg-green-100 text-green-700' },
  rejected:  { label: 'Rejected',  className: 'bg-red-100 text-red-700' },
}

interface ZatcaStatusBadgeProps {
  status: ZatcaInvoiceStatus
  className?: string
}

export function ZatcaStatusBadge({ status, className }: ZatcaStatusBadgeProps) {
  const { label, className: statusClass } = statusConfig[status]
  return (
    <span className={cn('inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium', statusClass, className)}>
      {label}
    </span>
  )
}
```

- [x] **Step 4: Run tests — verify they pass**

```bash
pnpm --filter @erp/ui test
```

Expected: 4 tests PASS.

- [x] **Step 5: Commit**

```bash
git add packages/ui/src/components/zatca/
git commit -m "feat(ui): add ZatcaStatusBadge component with tests"
```

---

## Phase 6: Staff App Wiring

### Task 9b: AppShell, Sidebar, TopBar, PageHeader layout components

**Files:**
- Create: `packages/ui/src/components/AppShell.tsx`
- Create: `packages/ui/src/components/Sidebar.tsx`
- Create: `packages/ui/src/components/TopBar.tsx`
- Create: `packages/ui/src/components/PageHeader.tsx`
- Create: `packages/ui/src/components/DataCard.tsx`
- Create: `packages/ui/src/components/ConfirmDialog.tsx`
- Create: `packages/ui/src/components/ErrorBoundary.tsx`

- [x] **Step 1: Create `packages/ui/src/components/AppShell.tsx`**

```tsx
import { useState } from 'react'
import { cn } from '../lib/utils'

interface AppShellProps {
  sidebar: React.ReactNode
  topbar: React.ReactNode
  children: React.ReactNode
}

export function AppShell({ sidebar, topbar, children }: AppShellProps) {
  const [collapsed, setCollapsed] = useState(false)
  return (
    <div className="flex h-screen bg-gray-50">
      <aside className={cn('flex flex-col bg-white border-r border-gray-200 transition-all duration-200', collapsed ? 'w-16' : 'w-64')}>
        <button
          onClick={() => setCollapsed((c) => !c)}
          className="p-3 text-gray-500 hover:text-gray-900 self-end"
          aria-label={collapsed ? 'Expand sidebar' : 'Collapse sidebar'}
        >
          {collapsed ? '→' : '←'}
        </button>
        {sidebar}
      </aside>
      <div className="flex flex-col flex-1 min-w-0">
        <header className="bg-white border-b border-gray-200 h-14 flex items-center px-4">
          {topbar}
        </header>
        <main className="flex-1 overflow-auto">{children}</main>
      </div>
    </div>
  )
}
```

- [x] **Step 2: Create `packages/ui/src/components/Sidebar.tsx`**

```tsx
import { cn } from '../lib/utils'

export interface NavItem {
  label: string
  href: string
  icon?: React.ReactNode
  permission?: string
  children?: NavItem[]
}

interface SidebarProps {
  items: NavItem[]
  permissions: string[]
  currentPath: string
  collapsed?: boolean
}

function hasPermission(item: NavItem, permissions: string[]): boolean {
  if (!item.permission) return true
  return permissions.includes(item.permission)
}

export function Sidebar({ items, permissions, currentPath, collapsed = false }: SidebarProps) {
  const visible = items.filter((item) => hasPermission(item, permissions))
  return (
    <nav className="flex-1 overflow-y-auto px-2 py-3 space-y-1">
      {visible.map((item) => (
        <a
          key={item.href}
          href={item.href}
          className={cn(
            'flex items-center gap-2 px-3 py-2 rounded-md text-sm font-medium transition-colors',
            currentPath.startsWith(item.href)
              ? 'bg-blue-50 text-blue-700'
              : 'text-gray-700 hover:bg-gray-100',
          )}
        >
          {item.icon && <span className="flex-shrink-0 w-5 h-5">{item.icon}</span>}
          {!collapsed && <span>{item.label}</span>}
        </a>
      ))}
    </nav>
  )
}
```

- [x] **Step 3: Write test for Sidebar permission gating**

Create `packages/ui/src/components/Sidebar.test.tsx`:

```tsx
import { render, screen } from '@testing-library/react'
import { describe, it, expect } from 'vitest'
import { Sidebar, NavItem } from './Sidebar'

const items: NavItem[] = [
  { label: 'Dashboard', href: '/app/dashboard' },
  { label: 'Compliance', href: '/app/compliance', permission: 'compliance.view' },
  { label: 'Accounting', href: '/app/accounting', permission: 'accounting.view' },
]

describe('Sidebar', () => {
  it('shows all items when all permissions granted', () => {
    render(<Sidebar items={items} permissions={['compliance.view', 'accounting.view']} currentPath="/app/dashboard" />)
    expect(screen.getByText('Dashboard')).toBeInTheDocument()
    expect(screen.getByText('Compliance')).toBeInTheDocument()
    expect(screen.getByText('Accounting')).toBeInTheDocument()
  })

  it('hides items when permission missing', () => {
    render(<Sidebar items={items} permissions={['compliance.view']} currentPath="/app/dashboard" />)
    expect(screen.getByText('Compliance')).toBeInTheDocument()
    expect(screen.queryByText('Accounting')).not.toBeInTheDocument()
  })

  it('shows no-permission items regardless', () => {
    render(<Sidebar items={items} permissions={[]} currentPath="/app/dashboard" />)
    expect(screen.getByText('Dashboard')).toBeInTheDocument()
  })
})
```

- [x] **Step 4: Run test — verify it passes**

```bash
pnpm --filter @erp/ui test
```

Expected: Sidebar tests PASS.

- [x] **Step 5: Create `packages/ui/src/components/TopBar.tsx`**

```tsx
interface TopBarProps {
  organizationName: string
  organizations: { id: string; name: string }[]
  onSwitchOrg: (orgId: string) => void
  userName: string
  onLogout: () => void
  notificationCount?: number
}

export function TopBar({
  organizationName,
  organizations,
  onSwitchOrg,
  userName,
  onLogout,
  notificationCount = 0,
}: TopBarProps) {
  return (
    <div className="flex items-center justify-between w-full">
      <div className="flex items-center gap-4">
        <span className="font-semibold text-gray-900">ERP</span>
        {organizations.length > 1 ? (
          <select
            value={organizationName}
            onChange={(e) => onSwitchOrg(e.target.value)}
            className="text-sm border border-gray-200 rounded px-2 py-1 text-gray-700"
          >
            {organizations.map((org) => (
              <option key={org.id} value={org.id}>{org.name}</option>
            ))}
          </select>
        ) : (
          <span className="text-sm text-gray-600">{organizationName}</span>
        )}
      </div>
      <div className="flex items-center gap-3">
        {notificationCount > 0 && (
          <button className="relative text-gray-500 hover:text-gray-900">
            🔔
            <span className="absolute -top-1 -right-1 bg-red-500 text-white text-xs rounded-full w-4 h-4 flex items-center justify-center">
              {notificationCount}
            </span>
          </button>
        )}
        <div className="flex items-center gap-2">
          <span className="text-sm text-gray-700">{userName}</span>
          <button onClick={onLogout} className="text-sm text-gray-500 hover:text-gray-900">Sign out</button>
        </div>
      </div>
    </div>
  )
}
```

- [x] **Step 6: Create `packages/ui/src/components/PageHeader.tsx`**

```tsx
interface PageHeaderProps {
  title: string
  breadcrumbs?: { label: string; href?: string }[]
  actions?: React.ReactNode
}

export function PageHeader({ title, breadcrumbs, actions }: PageHeaderProps) {
  return (
    <div className="flex items-start justify-between mb-6">
      <div>
        {breadcrumbs && breadcrumbs.length > 0 && (
          <nav className="flex items-center gap-1 text-xs text-gray-500 mb-1">
            {breadcrumbs.map((crumb, i) => (
              <span key={i} className="flex items-center gap-1">
                {i > 0 && <span>/</span>}
                {crumb.href ? <a href={crumb.href} className="hover:underline">{crumb.label}</a> : <span>{crumb.label}</span>}
              </span>
            ))}
          </nav>
        )}
        <h1 className="text-2xl font-bold text-gray-900">{title}</h1>
      </div>
      {actions && <div className="flex items-center gap-2">{actions}</div>}
    </div>
  )
}
```

- [x] **Step 7: Create `packages/ui/src/components/DataCard.tsx`**

```tsx
import { cn } from '../lib/utils'

interface DataCardProps {
  title: string
  value: string | number
  subtitle?: string
  trend?: { value: number; label: string }
  className?: string
}

export function DataCard({ title, value, subtitle, trend, className }: DataCardProps) {
  return (
    <div className={cn('rounded-lg border border-gray-200 bg-white p-5', className)}>
      <p className="text-sm font-medium text-gray-500">{title}</p>
      <p className="mt-2 text-3xl font-bold text-gray-900">{value}</p>
      {subtitle && <p className="mt-1 text-xs text-gray-500">{subtitle}</p>}
      {trend && (
        <p className={cn('mt-2 text-xs font-medium', trend.value >= 0 ? 'text-green-600' : 'text-red-600')}>
          {trend.value >= 0 ? '↑' : '↓'} {Math.abs(trend.value)}% {trend.label}
        </p>
      )}
    </div>
  )
}
```

- [x] **Step 8: Create `packages/ui/src/components/ConfirmDialog.tsx`**

```tsx
interface ConfirmDialogProps {
  open: boolean
  title: string
  description: string
  confirmLabel?: string
  cancelLabel?: string
  onConfirm: () => void
  onCancel: () => void
  variant?: 'danger' | 'default'
}

export function ConfirmDialog({
  open,
  title,
  description,
  confirmLabel = 'Confirm',
  cancelLabel = 'Cancel',
  onConfirm,
  onCancel,
  variant = 'default',
}: ConfirmDialogProps) {
  if (!open) return null
  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center">
      <div className="absolute inset-0 bg-black/50" onClick={onCancel} />
      <div className="relative bg-white rounded-lg shadow-xl p-6 max-w-sm w-full mx-4">
        <h2 className="text-lg font-semibold text-gray-900">{title}</h2>
        <p className="mt-2 text-sm text-gray-600">{description}</p>
        <div className="mt-6 flex justify-end gap-3">
          <button onClick={onCancel}
            className="px-4 py-2 text-sm font-medium text-gray-700 border border-gray-300 rounded hover:bg-gray-50">
            {cancelLabel}
          </button>
          <button onClick={onConfirm}
            className={`px-4 py-2 text-sm font-medium text-white rounded ${variant === 'danger' ? 'bg-red-600 hover:bg-red-700' : 'bg-blue-600 hover:bg-blue-700'}`}>
            {confirmLabel}
          </button>
        </div>
      </div>
    </div>
  )
}
```

- [x] **Step 9: Create `packages/ui/src/components/ErrorBoundary.tsx`**

```tsx
import { Component, ErrorInfo, ReactNode } from 'react'

interface Props { children: ReactNode; fallback?: ReactNode }
interface State { hasError: boolean; error: Error | null }

export class ErrorBoundary extends Component<Props, State> {
  state: State = { hasError: false, error: null }

  static getDerivedStateFromError(error: Error): State {
    return { hasError: true, error }
  }

  componentDidCatch(error: Error, info: ErrorInfo) {
    console.error('ErrorBoundary caught:', error, info)
  }

  render() {
    if (this.state.hasError) {
      return this.props.fallback ?? (
        <div className="flex flex-col items-center justify-center p-12 text-center">
          <h2 className="text-lg font-semibold text-gray-900">Something went wrong</h2>
          <p className="mt-1 text-sm text-gray-500">{this.state.error?.message}</p>
          <button onClick={() => this.setState({ hasError: false, error: null })}
            className="mt-4 text-sm text-blue-600 hover:underline">
            Try again
          </button>
        </div>
      )
    }
    return this.props.children
  }
}
```

- [x] **Step 10: Run tests**

```bash
pnpm --filter @erp/ui test
```

Expected: All tests PASS including new Sidebar tests.

- [x] **Step 11: Commit**

```bash
git add packages/ui/src/components/
git commit -m "feat(ui): add AppShell, Sidebar, TopBar, PageHeader, DataCard, ConfirmDialog, ErrorBoundary"
```

---

### Task 10: Staff app entry point and router

**Files:**
- Modify: `apps/staff/src/main.tsx`
- Create: `apps/staff/src/router.tsx`
- Create: `apps/staff/src/routes/__root.tsx`
- Create: `apps/staff/src/routes/login.tsx`
- Create: `apps/staff/src/routes/app/route.tsx`

- [x] **Step 1: Create `apps/staff/src/router.tsx`**

```tsx
import { createRouter } from '@tanstack/react-router'
import { routeTree } from './routeTree.gen'

export const router = createRouter({ routeTree })

declare module '@tanstack/react-router' {
  interface Register {
    router: typeof router
  }
}
```

- [x] **Step 2: Create `apps/staff/src/routes/__root.tsx`**

```tsx
import { createRootRoute, Outlet } from '@tanstack/react-router'
import { QueryClientProvider } from '@tanstack/react-query'
import { createQueryClient } from '@erp/api-client'

const queryClient = createQueryClient()

export const Route = createRootRoute({
  component: () => (
    <QueryClientProvider client={queryClient}>
      <Outlet />
    </QueryClientProvider>
  ),
})
```

- [x] **Step 3: Create `apps/staff/src/routes/login.tsx`**

```tsx
import { createFileRoute, useNavigate } from '@tanstack/react-router'
import { useState } from 'react'
import { useAuthStore } from '../store/auth'
import { getApiClient } from '@erp/api-client'
import type { ApiResponse, User, Organization } from '@erp/types'

export const Route = createFileRoute('/login')({
  component: LoginPage,
})

function LoginPage() {
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [error, setError] = useState('')
  const [loading, setLoading] = useState(false)
  const { setAuth } = useAuthStore()
  const navigate = useNavigate()

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault()
    setLoading(true)
    setError('')
    try {
      const { data } = await getApiClient().post<ApiResponse<{ token: string; user: User; organizations: Organization[] }>>(
        '/auth/login',
        { email, password },
      )
      const { token, user, organizations } = data.data
      if (organizations.length === 1) {
        setAuth(token, user, organizations, organizations[0])
        navigate({ to: '/app/dashboard' })
      } else {
        // Multiple orgs — store token temporarily and go to org picker
        setAuth(token, user, organizations, organizations[0])
        navigate({ to: '/org-picker' })
      }
    } catch {
      setError('Invalid email or password')
    } finally {
      setLoading(false)
    }
  }

  return (
    <div className="min-h-screen flex items-center justify-center bg-gray-50">
      <div className="max-w-md w-full bg-white rounded-lg shadow p-8">
        <h1 className="text-2xl font-bold text-gray-900 mb-6">Sign in to ERP</h1>
        <form onSubmit={handleSubmit} className="space-y-4">
          <div>
            <label className="block text-sm font-medium text-gray-700">Email</label>
            <input
              type="email"
              value={email}
              onChange={(e) => setEmail(e.target.value)}
              required
              className="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
            />
          </div>
          <div>
            <label className="block text-sm font-medium text-gray-700">Password</label>
            <input
              type="password"
              value={password}
              onChange={(e) => setPassword(e.target.value)}
              required
              className="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
            />
          </div>
          {error && <p className="text-sm text-red-600">{error}</p>}
          <button
            type="submit"
            disabled={loading}
            className="w-full bg-blue-600 text-white rounded-md py-2 text-sm font-medium hover:bg-blue-700 disabled:opacity-50"
          >
            {loading ? 'Signing in...' : 'Sign in'}
          </button>
        </form>
      </div>
    </div>
  )
}
```

- [x] **Step 4: Create protected route `apps/staff/src/routes/app/route.tsx`**

```tsx
import { createFileRoute, redirect } from '@tanstack/react-router'
import { useAuthStore } from '../../store/auth'

export const Route = createFileRoute('/app')({
  beforeLoad: () => {
    const { token } = useAuthStore.getState()
    if (!token) throw redirect({ to: '/login' })
  },
})
```

- [x] **Step 5: Update `apps/staff/src/main.tsx`**

```tsx
import React from 'react'
import ReactDOM from 'react-dom/client'
import { RouterProvider } from '@tanstack/react-router'
import { router } from './router'
import { initApiClient } from '@erp/api-client'
import { useAuthStore } from './store/auth'
import './index.css'

// Initialize the shared Axios client with auth store getters
initApiClient(
  import.meta.env.VITE_API_URL as string,
  () => useAuthStore.getState().token,
  () => useAuthStore.getState().organization?.id ?? null,
  () => useAuthStore.getState().logout(),
)

// Hydrate auth from localStorage on page load
useAuthStore.getState().hydrateFromStorage()

ReactDOM.createRoot(document.getElementById('root')!).render(
  <React.StrictMode>
    <RouterProvider router={router} />
  </React.StrictMode>,
)
```

- [x] **Step 6: Verify dev server starts**

```bash
pnpm --filter @erp/staff dev
```

Expected: Staff app runs at http://localhost:5173, login page visible.

- [x] **Step 7: Commit**

```bash
git add apps/staff/src/
git commit -m "feat(staff): wire router, auth guard, login page, Axios init"
```

---

## Phase 7: ZATCA Screens

### Task 11: ZATCA onboarding wizard component

**Files:**
- Create: `packages/ui/src/components/zatca/ZatcaOnboardingWizard.tsx`
- Create: `packages/ui/src/components/zatca/ZatcaOnboardingWizard.test.tsx`

- [x] **Step 1: Write failing test**

Create `packages/ui/src/components/zatca/ZatcaOnboardingWizard.test.tsx`:

```tsx
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, it, expect, vi } from 'vitest'
import { ZatcaOnboardingWizard } from './ZatcaOnboardingWizard'

const defaultProps = {
  status: 'not_started' as const,
  onRequestCcsid: vi.fn(),
  onUpgradeToPcsid: vi.fn(),
  isLoading: false,
}

describe('ZatcaOnboardingWizard', () => {
  it('shows step 1 active when status is not_started', () => {
    render(<ZatcaOnboardingWizard {...defaultProps} />)
    expect(screen.getByText('Request Compliance Certificate (CCSID)')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: /request ccsid/i })).toBeInTheDocument()
  })

  it('shows step 2 active when status is ccsid_requested', () => {
    render(<ZatcaOnboardingWizard {...defaultProps} status="ccsid_requested" />)
    expect(screen.getByRole('button', { name: /upgrade to production/i })).toBeInTheDocument()
  })

  it('shows completed state when pcsid_active', () => {
    render(<ZatcaOnboardingWizard {...defaultProps} status="pcsid_active" />)
    expect(screen.getByText('Onboarding Complete')).toBeInTheDocument()
  })

  it('calls onRequestCcsid when button clicked', async () => {
    const onRequestCcsid = vi.fn()
    render(<ZatcaOnboardingWizard {...defaultProps} onRequestCcsid={onRequestCcsid} />)
    await userEvent.click(screen.getByRole('button', { name: /request ccsid/i }))
    expect(onRequestCcsid).toHaveBeenCalledOnce()
  })
})
```

- [x] **Step 2: Run test — verify it fails**

```bash
pnpm --filter @erp/ui test
```

Expected: FAIL.

- [x] **Step 3: Create `packages/ui/src/components/zatca/ZatcaOnboardingWizard.tsx`**

```tsx
import { cn } from '../../lib/utils'
import type { ZatcaOnboardingStatus } from '@erp/types'

interface ZatcaOnboardingWizardProps {
  status: ZatcaOnboardingStatus
  onRequestCcsid: () => void
  onUpgradeToPcsid: () => void
  isLoading: boolean
  lastError?: string | null
}

const steps = [
  { id: 'ccsid', label: 'Request Compliance Certificate (CCSID)' },
  { id: 'compliance_check', label: 'Run Compliance Check' },
  { id: 'pcsid', label: 'Upgrade to Production (PCSID)' },
]

function getActiveStep(status: ZatcaOnboardingStatus): number {
  switch (status) {
    case 'not_started': return 0
    case 'csr_generated': return 0
    case 'ccsid_requested': return 1
    case 'compliance_check_passed': return 2
    case 'pcsid_active': return 3
    default: return 0
  }
}

export function ZatcaOnboardingWizard({
  status,
  onRequestCcsid,
  onUpgradeToPcsid,
  isLoading,
  lastError,
}: ZatcaOnboardingWizardProps) {
  const activeStep = getActiveStep(status)

  if (status === 'pcsid_active') {
    return (
      <div className="rounded-lg bg-green-50 p-6 text-center">
        <div className="text-green-600 text-4xl mb-2">✓</div>
        <h3 className="text-lg font-semibold text-green-900">Onboarding Complete</h3>
        <p className="text-sm text-green-700 mt-1">This branch is active on the ZATCA production network.</p>
      </div>
    )
  }

  return (
    <div className="rounded-lg border border-gray-200 p-6">
      <h3 className="text-base font-semibold text-gray-900 mb-6">ZATCA Device Onboarding</h3>

      {/* Steps */}
      <ol className="space-y-4 mb-6">
        {steps.map((step, index) => (
          <li key={step.id} className="flex items-start gap-3">
            <div className={cn(
              'flex-shrink-0 w-7 h-7 rounded-full flex items-center justify-center text-xs font-bold',
              index < activeStep ? 'bg-green-500 text-white' :
              index === activeStep ? 'bg-blue-600 text-white' :
              'bg-gray-200 text-gray-500'
            )}>
              {index < activeStep ? '✓' : index + 1}
            </div>
            <span className={cn(
              'text-sm pt-1',
              index === activeStep ? 'font-medium text-gray-900' : 'text-gray-500'
            )}>
              {step.label}
            </span>
          </li>
        ))}
      </ol>

      {/* Error */}
      {lastError && (
        <div className="mb-4 rounded bg-red-50 border border-red-200 px-3 py-2 text-sm text-red-700">
          {lastError}
        </div>
      )}

      {/* Action */}
      {activeStep === 0 && (
        <button
          onClick={onRequestCcsid}
          disabled={isLoading}
          className="bg-blue-600 text-white px-4 py-2 rounded text-sm font-medium hover:bg-blue-700 disabled:opacity-50"
        >
          {isLoading ? 'Requesting...' : 'Request CCSID'}
        </button>
      )}
      {activeStep === 2 && (
        <button
          onClick={onUpgradeToPcsid}
          disabled={isLoading}
          className="bg-blue-600 text-white px-4 py-2 rounded text-sm font-medium hover:bg-blue-700 disabled:opacity-50"
        >
          {isLoading ? 'Upgrading...' : 'Upgrade to Production (PCSID)'}
        </button>
      )}
    </div>
  )
}
```

- [x] **Step 4: Run tests — verify they pass**

```bash
pnpm --filter @erp/ui test
```

Expected: All tests PASS.

- [x] **Step 5: Commit**

```bash
git add packages/ui/src/components/zatca/ZatcaOnboardingWizard.tsx packages/ui/src/components/zatca/ZatcaOnboardingWizard.test.tsx
git commit -m "feat(ui): add ZatcaOnboardingWizard component with tests"
```

---

### Task 12: ZATCA onboarding page (staff app)

**Files:**
- Create: `apps/staff/src/routes/app/compliance/zatca/onboarding/index.tsx`

- [x] **Step 1: Create the onboarding index page**

```tsx
import { createFileRoute } from '@tanstack/react-router'
import { useZatcaOnboardingStatus, useRequestCcsid, useUpgradeToPcsid } from '@erp/api-client'
import { ZatcaOnboardingWizard, LoadingSpinner } from '@erp/ui'
import { useAuthStore } from '../../../../../store/auth'

export const Route = createFileRoute('/app/compliance/zatca/onboarding/')({
  component: OnboardingPage,
})

function OnboardingPage() {
  const { organization } = useAuthStore()
  // Use the first branch ID — in a real screen this comes from a branch picker
  const branchId = organization?.id ?? ''

  const { data: onboarding, isLoading, isError } = useZatcaOnboardingStatus(branchId)
  const requestCcsid = useRequestCcsid(branchId)
  const upgradeToPcsid = useUpgradeToPcsid(branchId)

  if (isLoading) return <div className="flex justify-center p-12"><LoadingSpinner size="lg" /></div>
  if (isError || !onboarding) return <div className="p-6 text-red-600">Failed to load onboarding status.</div>

  return (
    <div className="max-w-2xl mx-auto p-6">
      <h1 className="text-2xl font-bold text-gray-900 mb-2">ZATCA Onboarding</h1>
      <p className="text-sm text-gray-500 mb-6">
        Register this branch with the ZATCA e-invoicing network to enable invoice submission.
      </p>
      <ZatcaOnboardingWizard
        status={onboarding.status}
        onRequestCcsid={() => requestCcsid.mutate()}
        onUpgradeToPcsid={() => upgradeToPcsid.mutate()}
        isLoading={requestCcsid.isPending || upgradeToPcsid.isPending}
        lastError={onboarding.last_error}
      />
    </div>
  )
}
```

- [x] **Step 2: Verify page renders in dev server**

```bash
pnpm --filter @erp/staff dev
```

Navigate to http://localhost:5173/app/compliance/zatca/onboarding — should show wizard.

- [x] **Step 3: Commit**

```bash
git add apps/staff/src/routes/app/compliance/
git commit -m "feat(staff): add ZATCA onboarding page"
```

---

### Task 13: ZATCA invoice list with AG Grid

**Files:**
- Create: `apps/staff/src/routes/app/compliance/zatca/invoices/index.tsx`

- [x] **Step 1: Install AG Grid in staff app**

```bash
pnpm --filter @erp/staff add ag-grid-react ag-grid-community
```

- [x] **Step 2: Create invoice list page**

Create `apps/staff/src/routes/app/compliance/zatca/invoices/index.tsx`:

```tsx
import { createFileRoute, Link } from '@tanstack/react-router'
import { AgGridReact } from 'ag-grid-react'
import { ColDef } from 'ag-grid-community'
import { useZatcaInvoices, useSubmitInvoice, useRetryInvoice } from '@erp/api-client'
import { ZatcaStatusBadge, LoadingSpinner, EmptyState } from '@erp/ui'
import type { ZatcaInvoice } from '@erp/types'
import 'ag-grid-community/styles/ag-grid.css'
import 'ag-grid-community/styles/ag-theme-quartz.css'

export const Route = createFileRoute('/app/compliance/zatca/invoices/')({
  component: ZatcaInvoicesPage,
})

function ZatcaInvoicesPage() {
  const { data, isLoading } = useZatcaInvoices({ per_page: 100 })
  const submitInvoice = useSubmitInvoice()
  const retryInvoice = useRetryInvoice()

  const columnDefs: ColDef<ZatcaInvoice>[] = [
    { field: 'invoice_number', headerName: 'Invoice #', width: 140 },
    { field: 'buyer_name', headerName: 'Buyer', flex: 1 },
    {
      field: 'total_amount',
      headerName: 'Amount',
      width: 120,
      valueFormatter: ({ value, data: row }) =>
        `${row?.currency ?? ''} ${Number(value).toLocaleString()}`,
    },
    {
      field: 'vat_amount',
      headerName: 'VAT',
      width: 100,
      valueFormatter: ({ value, data: row }) =>
        `${row?.currency ?? ''} ${Number(value).toLocaleString()}`,
    },
    {
      field: 'submitted_at',
      headerName: 'Submitted',
      width: 160,
      valueFormatter: ({ value }) =>
        value ? new Date(value as string).toLocaleDateString() : '—',
    },
    {
      field: 'status',
      headerName: 'Status',
      width: 110,
      cellRenderer: ({ value }: { value: ZatcaInvoice['status'] }) => (
        <ZatcaStatusBadge status={value} />
      ),
    },
    {
      headerName: 'Actions',
      width: 160,
      cellRenderer: ({ data: row }: { data: ZatcaInvoice }) => (
        <div className="flex gap-2 items-center h-full">
          <Link
            to="/app/compliance/zatca/invoices/$invoiceId"
            params={{ invoiceId: row.id }}
            className="text-blue-600 text-xs hover:underline"
          >
            View
          </Link>
          {row.status === 'pending' && (
            <button
              onClick={() => submitInvoice.mutate(row.id)}
              className="text-xs text-green-700 hover:underline"
            >
              Submit
            </button>
          )}
          {row.status === 'rejected' && (
            <button
              onClick={() => retryInvoice.mutate(row.id)}
              className="text-xs text-orange-700 hover:underline"
            >
              Retry
            </button>
          )}
        </div>
      ),
    },
  ]

  if (isLoading) return <div className="flex justify-center p-12"><LoadingSpinner size="lg" /></div>

  const rows = data?.data ?? []

  return (
    <div className="p-6">
      <div className="flex justify-between items-center mb-4">
        <h1 className="text-2xl font-bold text-gray-900">ZATCA Invoices</h1>
        <Link
          to="/app/compliance/zatca/invoices/create"
          className="bg-blue-600 text-white px-4 py-2 rounded text-sm font-medium hover:bg-blue-700"
        >
          New Invoice
        </Link>
      </div>

      {rows.length === 0 ? (
        <EmptyState
          title="No invoices yet"
          description="Create your first ZATCA e-invoice to get started."
        />
      ) : (
        <div className="ag-theme-quartz" style={{ height: 500, width: '100%' }}>
          <AgGridReact rowData={rows} columnDefs={columnDefs} pagination paginationPageSize={20} />
        </div>
      )}
    </div>
  )
}
```

- [x] **Step 3: Verify page renders**

```bash
pnpm --filter @erp/staff dev
```

Navigate to http://localhost:5173/app/compliance/zatca/invoices — grid should appear.

- [x] **Step 4: Commit**

```bash
git add apps/staff/src/routes/app/compliance/zatca/invoices/
git commit -m "feat(staff): add ZATCA invoice list with AG Grid"
```

---

### Task 14: Invoice create form

**Files:**
- Create: `apps/staff/src/routes/app/compliance/zatca/invoices/create.tsx`

- [x] **Step 1: Install React Hook Form + Zod in staff app (if not already)**

```bash
pnpm --filter @erp/staff add react-hook-form zod @hookform/resolvers
```

- [x] **Step 2: Create invoice create page**

Create `apps/staff/src/routes/app/compliance/zatca/invoices/create.tsx`:

```tsx
import { createFileRoute, useNavigate } from '@tanstack/react-router'
import { useForm, useFieldArray } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { useCreateZatcaInvoice } from '@erp/api-client'

const lineItemSchema = z.object({
  description: z.string().min(1, 'Required'),
  quantity: z.number().positive('Must be positive'),
  unit_price: z.number().positive('Must be positive'),
  vat_rate: z.number().min(0).max(100),
})

const createInvoiceSchema = z.object({
  buyer_name: z.string().min(1, 'Buyer name is required'),
  buyer_vat: z.string().min(15, 'VAT number must be 15 digits').max(15),
  invoice_type: z.enum(['standard', 'simplified']),
  currency: z.string().default('SAR'),
  line_items: z.array(lineItemSchema).min(1, 'At least one line item required'),
})

type CreateInvoiceForm = z.infer<typeof createInvoiceSchema>

export const Route = createFileRoute('/app/compliance/zatca/invoices/create')({
  component: CreateInvoicePage,
})

function CreateInvoicePage() {
  const navigate = useNavigate()
  const createInvoice = useCreateZatcaInvoice()

  const { register, control, handleSubmit, formState: { errors } } = useForm<CreateInvoiceForm>({
    resolver: zodResolver(createInvoiceSchema),
    defaultValues: {
      invoice_type: 'standard',
      currency: 'SAR',
      line_items: [{ description: '', quantity: 1, unit_price: 0, vat_rate: 15 }],
    },
  })

  const { fields, append, remove } = useFieldArray({ control, name: 'line_items' })

  async function onSubmit(values: CreateInvoiceForm) {
    try {
      await createInvoice.mutateAsync(values)
      navigate({ to: '/app/compliance/zatca/invoices' })
    } catch {
      // Errors displayed inline
    }
  }

  return (
    <div className="max-w-3xl mx-auto p-6">
      <h1 className="text-2xl font-bold text-gray-900 mb-6">New ZATCA Invoice</h1>
      <form onSubmit={handleSubmit(onSubmit)} className="space-y-6">

        {/* Buyer details */}
        <div className="rounded-lg border border-gray-200 p-4 space-y-4">
          <h2 className="font-semibold text-gray-800">Buyer Details</h2>
          <div className="grid grid-cols-2 gap-4">
            <div>
              <label className="block text-sm font-medium text-gray-700">Buyer Name</label>
              <input {...register('buyer_name')} className="mt-1 block w-full rounded border border-gray-300 px-3 py-2 text-sm" />
              {errors.buyer_name && <p className="text-xs text-red-600 mt-1">{errors.buyer_name.message}</p>}
            </div>
            <div>
              <label className="block text-sm font-medium text-gray-700">Buyer VAT Number</label>
              <input {...register('buyer_vat')} className="mt-1 block w-full rounded border border-gray-300 px-3 py-2 text-sm" />
              {errors.buyer_vat && <p className="text-xs text-red-600 mt-1">{errors.buyer_vat.message}</p>}
            </div>
          </div>
          <div className="grid grid-cols-2 gap-4">
            <div>
              <label className="block text-sm font-medium text-gray-700">Invoice Type</label>
              <select {...register('invoice_type')} className="mt-1 block w-full rounded border border-gray-300 px-3 py-2 text-sm">
                <option value="standard">Standard (B2B)</option>
                <option value="simplified">Simplified (B2C)</option>
              </select>
            </div>
            <div>
              <label className="block text-sm font-medium text-gray-700">Currency</label>
              <select {...register('currency')} className="mt-1 block w-full rounded border border-gray-300 px-3 py-2 text-sm">
                <option value="SAR">SAR</option>
                <option value="USD">USD</option>
              </select>
            </div>
          </div>
        </div>

        {/* Line items */}
        <div className="rounded-lg border border-gray-200 p-4">
          <div className="flex justify-between items-center mb-4">
            <h2 className="font-semibold text-gray-800">Line Items</h2>
            <button
              type="button"
              onClick={() => append({ description: '', quantity: 1, unit_price: 0, vat_rate: 15 })}
              className="text-sm text-blue-600 hover:underline"
            >
              + Add Line
            </button>
          </div>
          {fields.map((field, index) => (
            <div key={field.id} className="grid grid-cols-12 gap-2 mb-3 items-start">
              <div className="col-span-4">
                {index === 0 && <label className="block text-xs font-medium text-gray-600 mb-1">Description</label>}
                <input {...register(`line_items.${index}.description`)} placeholder="Description"
                  className="block w-full rounded border border-gray-300 px-2 py-1.5 text-sm" />
              </div>
              <div className="col-span-2">
                {index === 0 && <label className="block text-xs font-medium text-gray-600 mb-1">Qty</label>}
                <input {...register(`line_items.${index}.quantity`, { valueAsNumber: true })} type="number"
                  className="block w-full rounded border border-gray-300 px-2 py-1.5 text-sm" />
              </div>
              <div className="col-span-3">
                {index === 0 && <label className="block text-xs font-medium text-gray-600 mb-1">Unit Price</label>}
                <input {...register(`line_items.${index}.unit_price`, { valueAsNumber: true })} type="number" step="0.01"
                  className="block w-full rounded border border-gray-300 px-2 py-1.5 text-sm" />
              </div>
              <div className="col-span-2">
                {index === 0 && <label className="block text-xs font-medium text-gray-600 mb-1">VAT %</label>}
                <input {...register(`line_items.${index}.vat_rate`, { valueAsNumber: true })} type="number"
                  className="block w-full rounded border border-gray-300 px-2 py-1.5 text-sm" />
              </div>
              <div className="col-span-1 flex items-end pb-0.5">
                {fields.length > 1 && (
                  <button type="button" onClick={() => remove(index)}
                    className="text-red-500 hover:text-red-700 text-lg leading-none mt-5">×</button>
                )}
              </div>
            </div>
          ))}
          {errors.line_items && <p className="text-xs text-red-600 mt-1">{errors.line_items.message}</p>}
        </div>

        <div className="flex gap-3">
          <button type="submit" disabled={createInvoice.isPending}
            className="bg-blue-600 text-white px-6 py-2 rounded text-sm font-medium hover:bg-blue-700 disabled:opacity-50">
            {createInvoice.isPending ? 'Creating...' : 'Create Invoice'}
          </button>
          <button type="button" onClick={() => navigate({ to: '/app/compliance/zatca/invoices' })}
            className="border border-gray-300 text-gray-700 px-6 py-2 rounded text-sm font-medium hover:bg-gray-50">
            Cancel
          </button>
        </div>
      </form>
    </div>
  )
}
```

- [x] **Step 3: Verify in dev server**

Navigate to http://localhost:5173/app/compliance/zatca/invoices/create — form should render with line items.

- [x] **Step 4: Commit**

```bash
git add apps/staff/src/routes/app/compliance/zatca/invoices/create.tsx
git commit -m "feat(staff): add ZATCA invoice create form with Zod validation"
```

---

### Task 15: Compliance reports page

**Files:**
- Create: `packages/ui/src/components/zatca/ComplianceStatsCard.tsx`
- Create: `apps/staff/src/routes/app/compliance/zatca/reports/index.tsx`

- [x] **Step 1: Create `packages/ui/src/components/zatca/ComplianceStatsCard.tsx`**

```tsx
import { cn } from '../../lib/utils'

interface ComplianceStatsCardProps {
  title: string
  value: string | number
  subtitle?: string
  variant?: 'default' | 'success' | 'warning' | 'danger'
  className?: string
}

const variants = {
  default: 'bg-white border-gray-200',
  success: 'bg-green-50 border-green-200',
  warning: 'bg-yellow-50 border-yellow-200',
  danger: 'bg-red-50 border-red-200',
}

export function ComplianceStatsCard({
  title,
  value,
  subtitle,
  variant = 'default',
  className,
}: ComplianceStatsCardProps) {
  return (
    <div className={cn('rounded-lg border p-5', variants[variant], className)}>
      <p className="text-sm font-medium text-gray-500">{title}</p>
      <p className="mt-2 text-3xl font-bold text-gray-900">{value}</p>
      {subtitle && <p className="mt-1 text-xs text-gray-500">{subtitle}</p>}
    </div>
  )
}
```

- [x] **Step 2: Create compliance reports page**

Create `apps/staff/src/routes/app/compliance/zatca/reports/index.tsx`:

```tsx
import { createFileRoute } from '@tanstack/react-router'
import { useState } from 'react'
import { useComplianceReport } from '@erp/api-client'
import { ComplianceStatsCard, LoadingSpinner } from '@erp/ui'

export const Route = createFileRoute('/app/compliance/zatca/reports/')({
  component: ZatcaReportsPage,
})

function ZatcaReportsPage() {
  const [dateRange] = useState({
    start: new Date(new Date().getFullYear(), new Date().getMonth(), 1).toISOString().slice(0, 10),
    end: new Date().toISOString().slice(0, 10),
  })

  const { data: report, isLoading, isError } = useComplianceReport(dateRange)

  if (isLoading) return <div className="flex justify-center p-12"><LoadingSpinner size="lg" /></div>
  if (isError || !report) return <div className="p-6 text-red-600">Failed to load compliance report.</div>

  return (
    <div className="p-6">
      <h1 className="text-2xl font-bold text-gray-900 mb-2">Compliance Report</h1>
      <p className="text-sm text-gray-500 mb-6">
        {dateRange.start} to {dateRange.end}
        {report.last_submission_at && (
          <> · Last submission: {new Date(report.last_submission_at).toLocaleString()}</>
        )}
      </p>

      <div className="grid grid-cols-2 gap-4 sm:grid-cols-4 mb-8">
        <ComplianceStatsCard
          title="Total Submitted"
          value={report.total_submitted}
        />
        <ComplianceStatsCard
          title="Cleared"
          value={report.total_cleared}
          variant="success"
        />
        <ComplianceStatsCard
          title="Rejected"
          value={report.total_rejected}
          variant="danger"
        />
        <ComplianceStatsCard
          title="Pending"
          value={report.total_pending}
          variant="warning"
        />
      </div>

      <div className="grid grid-cols-2 gap-4 sm:grid-cols-2">
        <ComplianceStatsCard
          title="Clearance Rate"
          value={`${report.clearance_rate.toFixed(1)}%`}
          subtitle="% of submitted invoices cleared"
          variant={report.clearance_rate >= 90 ? 'success' : 'warning'}
        />
        <ComplianceStatsCard
          title="Rejection Rate"
          value={`${report.rejection_rate.toFixed(1)}%`}
          subtitle="% of submitted invoices rejected"
          variant={report.rejection_rate > 5 ? 'danger' : 'default'}
        />
      </div>
    </div>
  )
}
```

- [x] **Step 3: Verify in dev server**

Navigate to http://localhost:5173/app/compliance/zatca/reports — stats cards should appear.

- [x] **Step 4: Commit**

```bash
git add packages/ui/src/components/zatca/ComplianceStatsCard.tsx apps/staff/src/routes/app/compliance/zatca/reports/
git commit -m "feat(staff): add ZATCA compliance reports page with stats cards"
```

---

## Phase 8: End-to-End Tests

### Task 16: Playwright E2E tests

**Files:**
- Create: `apps/staff/e2e/zatca.spec.ts`
- Create: `apps/staff/playwright.config.ts`

- [x] **Step 1: Install Playwright**

```bash
pnpm --filter @erp/staff add -D @playwright/test
pnpm --filter @erp/staff exec playwright install chromium
```

- [x] **Step 2: Create `apps/staff/playwright.config.ts`**

```ts
import { defineConfig } from '@playwright/test'

export default defineConfig({
  testDir: './e2e',
  use: {
    baseURL: 'http://localhost:5173',
    headless: true,
  },
  webServer: {
    command: 'pnpm dev',
    port: 5173,
    reuseExistingServer: true,
  },
})
```

- [x] **Step 3: Create `apps/staff/e2e/zatca.spec.ts`**

```ts
import { test, expect } from '@playwright/test'

test.describe('ZATCA module', () => {
  test.beforeEach(async ({ page }) => {
    // Inject a mock token so we bypass the login page
    await page.addInitScript(() => {
      localStorage.setItem('erp_token', 'test-token')
      localStorage.setItem('erp_org_id', 'org-1')
    })
  })

  test('onboarding page shows wizard', async ({ page }) => {
    await page.goto('/app/compliance/zatca/onboarding')
    await expect(page.getByText('ZATCA Device Onboarding')).toBeVisible()
    await expect(page.getByRole('button', { name: /request ccsid/i })).toBeVisible()
  })

  test('invoice list page renders grid', async ({ page }) => {
    await page.goto('/app/compliance/zatca/invoices')
    await expect(page.getByText('ZATCA Invoices')).toBeVisible()
    await expect(page.getByRole('link', { name: 'New Invoice' })).toBeVisible()
  })

  test('create invoice form shows validation', async ({ page }) => {
    await page.goto('/app/compliance/zatca/invoices/create')
    await expect(page.getByText('New ZATCA Invoice')).toBeVisible()
    await page.getByRole('button', { name: 'Create Invoice' }).click()
    await expect(page.getByText('Buyer name is required')).toBeVisible()
  })

  test('reports page shows stats cards', async ({ page }) => {
    await page.goto('/app/compliance/zatca/reports')
    await expect(page.getByText('Compliance Report')).toBeVisible()
    await expect(page.getByText('Total Submitted')).toBeVisible()
    await expect(page.getByText('Clearance Rate')).toBeVisible()
  })
})
```

- [x] **Step 4: Run E2E tests (with dev server running)**

```bash
pnpm --filter @erp/staff dev &
pnpm --filter @erp/staff exec playwright test
```

Expected: 4 tests PASS.

- [x] **Step 5: Commit**

```bash
git add apps/staff/e2e/ apps/staff/playwright.config.ts
git commit -m "test(staff): add Playwright E2E tests for ZATCA module"
```

---

## Phase 9: Final Wiring

### Task 16b: Add logo asset to all three apps

**Files:**
- Create: `apps/staff/public/logo.svg`
- Create: `apps/admin/public/logo.svg`
- Create: `apps/portal/public/logo.svg`
- Create: `packages/ui/src/components/Logo.tsx`

The logo SVG source is at `docs/superpowers/assets/logo.svg` in the backend repo.

- [x] **Step 1: Copy logo to all three app public directories**

Create `apps/staff/public/logo.svg`, `apps/admin/public/logo.svg`, `apps/portal/public/logo.svg` — all with the same content:

```svg
<svg width="120" height="120" viewBox="0 0 120 120" xmlns="http://www.w3.org/2000/svg">
  <rect x="10" y="10" width="100" height="100" rx="20" fill="#1A1F36"/>
  <path d="M30 75 C40 40, 60 40, 70 75 S100 100, 95 55"
        stroke="#14B8A6" stroke-width="4" fill="none"/>
  <circle cx="95" cy="55" r="4" fill="#EADBC8"/>
</svg>
```

- [x] **Step 2: Create `packages/ui/src/components/Logo.tsx`**

```tsx
interface LogoProps {
  size?: number
  className?: string
}

export function Logo({ size = 32, className }: LogoProps) {
  return (
    <img
      src="/logo.svg"
      alt="ERP Logo"
      width={size}
      height={size}
      className={className}
    />
  )
}
```

- [x] **Step 3: Update `TopBar` to use Logo instead of text**

In `packages/ui/src/components/TopBar.tsx`, replace:

```tsx
<span className="font-semibold text-gray-900">ERP</span>
```

with:

```tsx
<Logo size={28} />
```

And add the import at the top:

```tsx
import { Logo } from './Logo'
```

- [x] **Step 4: Update login page to show logo**

In `apps/staff/src/routes/login.tsx`, replace:

```tsx
<h1 className="text-2xl font-bold text-gray-900 mb-6">Sign in to ERP</h1>
```

with:

```tsx
<div className="flex flex-col items-center mb-6">
  <img src="/logo.svg" alt="ERP" width={56} height={56} className="mb-3" />
  <h1 className="text-2xl font-bold text-gray-900">Sign in to ERP</h1>
</div>
```

- [x] **Step 5: Update vendor portal header to use logo**

The portal `AppShell` equivalent header should display the logo prominently. In `apps/portal/src/main.tsx` (or the portal layout when built), the logo is referenced at `/logo.svg` from `apps/portal/public/`.

- [x] **Step 6: Export Logo from packages/ui**

Add to `packages/ui/src/index.ts`:

```ts
export * from './components/Logo'
```

- [x] **Step 7: Commit**

```bash
git add apps/staff/public/logo.svg apps/admin/public/logo.svg apps/portal/public/logo.svg packages/ui/src/components/Logo.tsx
git commit -m "feat(ui): add logo asset and Logo component, wire into TopBar and login page"
```

---

### Task 17: Create env files and verify full build

**Files:**
- Create: `apps/staff/.env.local`
- Create: `apps/admin/.env.local`
- Create: `apps/portal/.env.local`

- [x] **Step 1: Create env files**

`apps/staff/.env.local`:
```
VITE_API_URL=http://localhost:8000/api/v1
VITE_APP_NAME=ERP
```

`apps/admin/.env.local`:
```
VITE_API_URL=http://localhost:8000/api/v1
VITE_APP_NAME=ERP Admin
```

`apps/portal/.env.local`:
```
VITE_API_URL=http://localhost:8000/api/v1
VITE_APP_NAME=ERP Portal
```

- [x] **Step 2: Add `.env.local` to `.gitignore`**

Append to root `.gitignore`:
```
.env.local
.env.*.local
```

- [x] **Step 3: Run full typecheck**

```bash
pnpm typecheck
```

Expected: No errors across all packages and apps.

- [x] **Step 4: Run full test suite**

```bash
pnpm test
```

Expected: All Vitest tests pass.

- [x] **Step 5: Run full build**

```bash
pnpm build
```

Expected: `apps/staff/dist/`, `apps/admin/dist/`, `apps/portal/dist/` created.

- [x] **Step 6: Final commit**

```bash
git add .
git commit -m "chore: add env files to gitignore, verify full build passes"
```

---

## Summary

After all tasks are complete:

| What was built | Where |
|----------------|-------|
| Turborepo monorepo | `c:\laragon\www\erp-frontend\` |
| 3 Vite apps | `apps/staff`, `apps/admin`, `apps/portal` |
| Shared TypeScript types | `packages/types/` |
| Axios client + ZATCA hooks | `packages/api-client/` |
| Shared UI components | `packages/ui/` |
| JWT auth with Zustand | `apps/staff/src/store/auth.ts` |
| Login + route guard | `apps/staff/src/routes/login.tsx` |
| ZATCA onboarding wizard | Full wizard UI + page |
| ZATCA invoice AG Grid | Paginated table with actions |
| ZATCA invoice create form | Validated multi-line form |
| ZATCA compliance reports | Stats dashboard |
| Unit + integration tests | Vitest across packages |
| E2E tests | Playwright for ZATCA flows |

**Next module to add:** Accounting (Chart of Accounts, Journal Entries, Bank Reconciliation) — follows the same pattern: add types → add API hooks → add pages.
