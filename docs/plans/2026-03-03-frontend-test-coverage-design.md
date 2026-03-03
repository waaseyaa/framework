# Frontend Test Coverage Design

**Date:** 2026-03-03
**Status:** Approved

## Goal

Add industry-standard three-layer test coverage to the Nuxt 3 admin SPA. Currently zero test infrastructure exists; build verification is the only quality gate.

## Toolchain

| Tool | Role |
|---|---|
| Vitest | Test runner + assertions for unit and component layers |
| @nuxt/test-utils | `mountSuspended()` for component tests — boots real Nuxt context so auto-imports work without manual stubs |
| happy-dom | Lightweight DOM environment for Vitest (default) |
| MSW v2 | Shared API fixtures — `msw/node` in Vitest; same fixture objects re-used in Playwright via `page.route()` |
| @vitest/coverage-v8 | Code coverage reporting |
| Playwright | E2E browser tests against `nuxt dev`; `page.route()` intercepts `/api/*` (no PHP backend needed) |

Default Vitest environment is `happy-dom`. Component tests that need full Nuxt context opt in with `// @vitest-environment nuxt` per-file.

## Directory Structure

```
packages/admin/
├── tests/
│   ├── setup.ts                         # MSW node server (beforeAll/afterAll/afterEach)
│   ├── fixtures/
│   │   ├── entityTypes.ts               # /api/entity-types mock responses
│   │   └── schemas.ts                   # /api/schema/:type mock responses
│   ├── unit/
│   │   └── composables/
│   │       ├── useNavGroups.test.ts     # groupEntityTypes pure function
│   │       ├── useSchema.test.ts        # sortedProperties, caching, fetch/error states
│   │       ├── useEntity.test.ts        # list/get/create/update/remove/search
│   │       └── useLanguage.test.ts      # t() lookups, missing key fallback
│   └── components/
│       ├── layout/
│       │   └── NavBuilder.test.ts       # fetch → group → render nav sections
│       ├── schema/
│       │   ├── SchemaField.test.ts      # widget dispatch by x-widget value
│       │   └── SchemaForm.test.ts       # loading states, submit, error emit
│       └── widgets/
│           ├── TextInput.test.ts
│           ├── Toggle.test.ts
│           └── Select.test.ts
├── e2e/
│   ├── fixtures/
│   │   └── routes.ts                    # page.route() handlers using shared fixtures
│   ├── dashboard.spec.ts                # entity type cards render and link correctly
│   ├── navigation.spec.ts               # grouped nav renders, active state
│   └── entity-form.spec.ts              # create form: loads schema, submits, shows error
├── vitest.config.ts
└── playwright.config.ts
```

## What Gets Tested

### Unit — composables

| File | Key cases |
|---|---|
| `useNavGroups` | All 12 entity types grouped correctly; unknown type falls into "other"; empty input returns empty |
| `useSchema` | `sortedProperties` filtering (readOnly system fields excluded, x-access-restricted kept); x-weight sort order; caching prevents second `$fetch`; error state on API failure |
| `useEntity` | `search` returns `[]` when query < 2 chars; `list` appends correct query string params; `create` sends correct JSON:API body |
| `useLanguage` | Known key returns translation; missing key falls back to key name |

### Component tests

| File | Key cases |
|---|---|
| `NavBuilder` | Renders grouped nav sections on success; shows error message on fetch failure; "Dashboard" link always present |
| `SchemaField` | `x-widget: 'textarea'` mounts TextArea; `x-widget: 'boolean'` mounts Toggle; unknown widget falls back to TextInput; `x-access-restricted` sets `disabled` |
| `SchemaForm` | Shows loading spinner while schema fetches; shows error on schema failure; submit calls `create` for new entity, `update` for existing; emits `saved` on success, `error` on failure |
| `TextInput` / `Toggle` / `Select` | Renders label; emits `update:modelValue` on change; `disabled` prop prevents interaction |

### E2E — Playwright

| Spec | Scenarios |
|---|---|
| `dashboard.spec.ts` | All entity type cards render with correct labels and href; clicking a card navigates to correct route |
| `navigation.spec.ts` | Nav groups render with correct section headings; active link has correct style on matching route |
| `entity-form.spec.ts` | Create page loads schema, renders fields; submit sends POST, redirects on success; API error surfaces in form |

## Configuration

### vitest.config.ts

```ts
import { defineVitestConfig } from '@nuxt/test-utils/config'

export default defineVitestConfig({
  test: {
    environment: 'happy-dom',
    setupFiles: ['./tests/setup.ts'],
    coverage: {
      provider: 'v8',
      include: ['app/**/*.{ts,vue}'],
    },
  },
})
```

### playwright.config.ts

```ts
import { defineConfig, devices } from '@playwright/test'

export default defineConfig({
  testDir: './e2e',
  webServer: {
    command: 'npm run dev',
    url: 'http://localhost:3000',
    reuseExistingServer: !process.env.CI,
  },
  use: { baseURL: 'http://localhost:3000' },
  projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'] } }],
})
```

### New devDependencies

- `vitest`
- `@nuxt/test-utils`
- `@vue/test-utils`
- `happy-dom`
- `@vitest/coverage-v8`
- `msw` (v2)
- `@playwright/test`

### New scripts

```json
"test": "vitest run",
"test:watch": "vitest",
"test:coverage": "vitest run --coverage",
"test:e2e": "playwright test",
"test:e2e:ui": "playwright test --ui"
```
