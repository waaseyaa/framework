# Frontend Test Coverage Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Add three-layer test coverage (unit composables, component, E2E) to the Nuxt 3 admin SPA with zero prior test infrastructure.

**Architecture:** Vitest with `@nuxt/test-utils` `nuxt` environment globally so Nuxt auto-imports (`$fetch`, `NuxtLink`, composables) work without manual stubs. `vi.stubGlobal('$fetch', mockFn)` for API mocking in Vitest. Playwright with `page.route()` for E2E against `nuxt dev` — `onMounted` fetches are client-side so `page.route()` intercepts them without the PHP backend.

**Tech Stack:** Vitest 3, @nuxt/test-utils 3, @vue/test-utils 2, happy-dom, @vitest/coverage-v8, @playwright/test 1.x; no MSW (vi.stubGlobal + page.route() are sufficient).

---

### Task 1: Install dependencies and configure test infrastructure

**Files:**
- Modify: `packages/admin/package.json`
- Create: `packages/admin/vitest.config.ts`
- Create: `packages/admin/playwright.config.ts`
- Create: `packages/admin/tests/setup.ts`

**Step 1: Install Vitest and component testing dependencies**

```bash
cd packages/admin
npm install --save-dev vitest @nuxt/test-utils @vue/test-utils happy-dom @vitest/coverage-v8
```

Expected: packages installed, no peer dep errors.

**Step 2: Install Playwright**

```bash
cd packages/admin
npm install --save-dev @playwright/test
npx playwright install chromium
```

Expected: `node_modules/@playwright/test` present, chromium browser downloaded.

**Step 3: Add test scripts to package.json**

Edit `packages/admin/package.json` scripts section to add:

```json
"test": "vitest run",
"test:watch": "vitest",
"test:coverage": "vitest run --coverage",
"test:e2e": "playwright test",
"test:e2e:ui": "playwright test --ui"
```

**Step 4: Create vitest.config.ts**

```ts
// packages/admin/vitest.config.ts
import { defineVitestConfig } from '@nuxt/test-utils/config'

export default defineVitestConfig({
  test: {
    environment: 'nuxt',
    setupFiles: ['./tests/setup.ts'],
    restoreMocks: true,
    coverage: {
      provider: 'v8',
      include: ['app/**/*.{ts,vue}'],
      exclude: ['app/**/*.d.ts'],
    },
  },
})
```

**Step 5: Create tests/setup.ts**

```ts
// packages/admin/tests/setup.ts
// Global test setup — restoreMocks: true in vitest.config handles mock cleanup.
// Add any global beforeEach/afterEach hooks here as needed.
```

**Step 6: Create playwright.config.ts**

```ts
// packages/admin/playwright.config.ts
import { defineConfig, devices } from '@playwright/test'

export default defineConfig({
  testDir: './e2e',
  fullyParallel: true,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 2 : 0,
  reporter: 'html',
  use: {
    baseURL: 'http://localhost:3000',
    trace: 'on-first-retry',
  },
  projects: [
    {
      name: 'chromium',
      use: { ...devices['Desktop Chrome'] },
    },
  ],
  webServer: {
    command: 'npm run dev',
    url: 'http://localhost:3000',
    reuseExistingServer: !process.env.CI,
    timeout: 120 * 1000,
  },
})
```

**Step 7: Verify Vitest runs with no tests**

```bash
cd packages/admin && npm test
```

Expected: "No test files found" or 0 tests passed. No errors.

**Step 8: Commit**

```bash
git add packages/admin/package.json packages/admin/vitest.config.ts \
  packages/admin/playwright.config.ts packages/admin/tests/setup.ts \
  packages/admin/package-lock.json
git commit -m "feat(admin): add Vitest + @nuxt/test-utils + Playwright test infrastructure"
```

---

### Task 2: Create shared test fixtures

**Files:**
- Create: `packages/admin/tests/fixtures/entityTypes.ts`
- Create: `packages/admin/tests/fixtures/schemas.ts`

These fixtures are plain TypeScript objects imported by both Vitest tests (via `vi.stubGlobal`) and Playwright tests (via `page.route()`).

**Step 1: Create entityTypes fixture**

```ts
// packages/admin/tests/fixtures/entityTypes.ts
export const entityTypes = [
  { id: 'user', label: 'User' },
  { id: 'node', label: 'Content' },
  { id: 'node_type', label: 'Content Type' },
  { id: 'taxonomy_term', label: 'Taxonomy Term' },
  { id: 'taxonomy_vocabulary', label: 'Taxonomy Vocabulary' },
  { id: 'media', label: 'Media' },
  { id: 'media_type', label: 'Media Type' },
  { id: 'path_alias', label: 'Path Alias' },
  { id: 'menu', label: 'Menu' },
  { id: 'menu_link', label: 'Menu Link' },
  { id: 'workflow', label: 'Workflow' },
  { id: 'pipeline', label: 'Pipeline' },
]
```

**Step 2: Create schemas fixture**

```ts
// packages/admin/tests/fixtures/schemas.ts
import type { EntitySchema } from '~/composables/useSchema'

export const userSchema: EntitySchema = {
  $schema: 'http://json-schema.org/draft-07/schema#',
  title: 'User',
  description: 'A user account',
  type: 'object',
  'x-entity-type': 'user',
  'x-translatable': false,
  'x-revisionable': false,
  properties: {
    uid: {
      type: 'integer',
      readOnly: true,
      'x-weight': -10,
      'x-label': 'ID',
    },
    name: {
      type: 'string',
      'x-widget': 'text',
      'x-label': 'Username',
      'x-weight': 0,
      'x-required': true,
    },
    email: {
      type: 'string',
      format: 'email',
      'x-widget': 'email',
      'x-label': 'Email',
      'x-weight': 1,
      readOnly: true,
      'x-access-restricted': true,
    },
    status: {
      type: 'string',
      'x-widget': 'select',
      'x-label': 'Status',
      'x-weight': 2,
      enum: ['active', 'blocked'],
      'x-enum-labels': { active: 'Active', blocked: 'Blocked' },
    },
  },
  required: ['name'],
}
```

**Step 3: Verify TypeScript compiles**

```bash
cd packages/admin && npx tsc --noEmit
```

Expected: no errors.

**Step 4: Commit**

```bash
git add packages/admin/tests/
git commit -m "test(admin): add shared API response fixtures"
```

---

### Task 3: Unit tests — useNavGroups (pure function)

`groupEntityTypes` takes an array of entity type objects and returns them sorted into named groups. No Vue, no $fetch — pure function.

**Files:**
- Create: `packages/admin/tests/unit/composables/useNavGroups.test.ts`
- Reference: `packages/admin/app/composables/useNavGroups.ts`

**Step 1: Write the failing tests**

```ts
// packages/admin/tests/unit/composables/useNavGroups.test.ts
import { describe, it, expect } from 'vitest'
import { groupEntityTypes } from '~/composables/useNavGroups'

describe('groupEntityTypes', () => {
  it('places user into the people group', () => {
    const groups = groupEntityTypes([{ id: 'user', label: 'User' }])
    const people = groups.find((g) => g.key === 'people')
    expect(people?.entityTypes).toEqual([{ id: 'user', label: 'User' }])
  })

  it('places node and node_type into the content group', () => {
    const groups = groupEntityTypes([
      { id: 'node', label: 'Content' },
      { id: 'node_type', label: 'Content Type' },
    ])
    const content = groups.find((g) => g.key === 'content')
    expect(content?.entityTypes.map((e) => e.id)).toEqual(['node', 'node_type'])
  })

  it('omits groups that have no matching entity types', () => {
    const groups = groupEntityTypes([{ id: 'user', label: 'User' }])
    const keys = groups.map((g) => g.key)
    expect(keys).toContain('people')
    expect(keys).not.toContain('content')
    expect(keys).not.toContain('taxonomy')
  })

  it('places unknown entity types into an other group', () => {
    const groups = groupEntityTypes([{ id: 'custom_thing', label: 'Custom' }])
    expect(groups).toHaveLength(1)
    expect(groups[0].key).toBe('other')
    expect(groups[0].entityTypes).toEqual([{ id: 'custom_thing', label: 'Custom' }])
  })

  it('returns empty array for empty input', () => {
    expect(groupEntityTypes([])).toEqual([])
  })

  it('handles all 12 registered entity types without an other group', () => {
    const all = [
      { id: 'user', label: 'User' },
      { id: 'node', label: 'Content' },
      { id: 'node_type', label: 'Content Type' },
      { id: 'taxonomy_term', label: 'Term' },
      { id: 'taxonomy_vocabulary', label: 'Vocabulary' },
      { id: 'media', label: 'Media' },
      { id: 'media_type', label: 'Media Type' },
      { id: 'path_alias', label: 'Path Alias' },
      { id: 'menu', label: 'Menu' },
      { id: 'menu_link', label: 'Menu Link' },
      { id: 'workflow', label: 'Workflow' },
      { id: 'pipeline', label: 'Pipeline' },
    ]
    const groups = groupEntityTypes(all)
    const keys = groups.map((g) => g.key)
    expect(keys).not.toContain('other')
    const total = groups.reduce((sum, g) => sum + g.entityTypes.length, 0)
    expect(total).toBe(12)
  })
})
```

**Step 2: Run tests to verify they fail**

```bash
cd packages/admin && npm test -- tests/unit/composables/useNavGroups.test.ts
```

Expected: FAIL — "Cannot find module '~/composables/useNavGroups'" (Nuxt env not yet resolving `~`). If tests pass immediately, the logic was already correct — proceed.

**Step 3: Run again after Nuxt env is active (it already is from Task 1)**

The `environment: 'nuxt'` in vitest.config.ts sets up the `~` alias. The tests should pass against the existing `groupEntityTypes` implementation.

```bash
cd packages/admin && npm test -- tests/unit/composables/useNavGroups.test.ts
```

Expected: all 6 tests PASS.

**Step 4: Commit**

```bash
git add packages/admin/tests/unit/
git commit -m "test(admin): unit tests for groupEntityTypes pure function"
```

---

### Task 4: Unit tests — useSchema

Tests for `sortedProperties` filtering/sorting logic and `fetch()` caching. Uses `vi.stubGlobal('$fetch', mockFn)`.

**Files:**
- Create: `packages/admin/tests/unit/composables/useSchema.test.ts`
- Reference: `packages/admin/app/composables/useSchema.ts`

**Step 1: Write the failing tests**

```ts
// packages/admin/tests/unit/composables/useSchema.test.ts
import { describe, it, expect, vi, beforeEach } from 'vitest'
import { userSchema } from '../../fixtures/schemas'

// Import after stubbing $fetch so the module-level schemaCache is fresh per suite.
// We re-import useSchema in each test to get a fresh closure.

describe('sortedProperties', () => {
  it('returns all properties sorted by x-weight when editable=false', async () => {
    vi.stubGlobal('$fetch', vi.fn().mockResolvedValue({ meta: { schema: userSchema } }))
    const { useSchema } = await import('~/composables/useSchema')
    const { fetch, sortedProperties } = useSchema('user')
    await fetch()
    const props = sortedProperties(false)
    const names = props.map(([name]) => name)
    // uid (-10) before name (0) before email (1) before status (2)
    expect(names).toEqual(['uid', 'name', 'email', 'status'])
  })

  it('excludes system readOnly fields when editable=true', async () => {
    vi.stubGlobal('$fetch', vi.fn().mockResolvedValue({ meta: { schema: userSchema } }))
    const { useSchema } = await import('~/composables/useSchema')
    const { fetch, sortedProperties } = useSchema('user')
    await fetch()
    const props = sortedProperties(true)
    const names = props.map(([name]) => name)
    // uid is readOnly without x-access-restricted → excluded
    expect(names).not.toContain('uid')
  })

  it('keeps x-access-restricted fields when editable=true', async () => {
    vi.stubGlobal('$fetch', vi.fn().mockResolvedValue({ meta: { schema: userSchema } }))
    const { useSchema } = await import('~/composables/useSchema')
    const { fetch, sortedProperties } = useSchema('user')
    await fetch()
    const props = sortedProperties(true)
    const names = props.map(([name]) => name)
    // email is readOnly + x-access-restricted → kept (rendered as disabled widget)
    expect(names).toContain('email')
  })
})

describe('useSchema fetch and caching', () => {
  it('sets schema.value on successful fetch', async () => {
    const mockFetch = vi.fn().mockResolvedValue({ meta: { schema: userSchema } })
    vi.stubGlobal('$fetch', mockFetch)
    const { useSchema } = await import('~/composables/useSchema')
    const { schema, fetch } = useSchema('user_fresh')
    await fetch()
    expect(schema.value?.title).toBe('User')
  })

  it('does not call $fetch a second time for the same entity type', async () => {
    const mockFetch = vi.fn().mockResolvedValue({ meta: { schema: userSchema } })
    vi.stubGlobal('$fetch', mockFetch)
    const { useSchema } = await import('~/composables/useSchema')
    const instance = useSchema('user_cache')
    await instance.fetch()
    await instance.fetch()
    expect(mockFetch).toHaveBeenCalledTimes(1)
  })

  it('sets error.value when $fetch rejects', async () => {
    vi.stubGlobal('$fetch', vi.fn().mockRejectedValue(new Error('Network failure')))
    const { useSchema } = await import('~/composables/useSchema')
    const { error, fetch } = useSchema('user_error')
    await fetch()
    expect(error.value).toBe('Network failure')
  })

  it('clears cache after invalidate()', async () => {
    const mockFetch = vi.fn().mockResolvedValue({ meta: { schema: userSchema } })
    vi.stubGlobal('$fetch', mockFetch)
    const { useSchema } = await import('~/composables/useSchema')
    const instance = useSchema('user_invalidate')
    await instance.fetch()
    instance.invalidate()
    await instance.fetch()
    expect(mockFetch).toHaveBeenCalledTimes(2)
  })
})
```

> **Note on dynamic imports:** `useSchema` uses a module-level `schemaCache` Map. To test caching isolation, use unique entity type IDs per test (e.g., `'user_cache'`, `'user_fresh'`) so tests don't share cache entries. The `restoreMocks: true` config resets stubs but not module state.

**Step 2: Run tests to verify they fail**

```bash
cd packages/admin && npm test -- tests/unit/composables/useSchema.test.ts
```

Expected: tests fail with import errors or failing assertions. The implementation already exists — once the Nuxt env resolves imports, tests should pass.

**Step 3: Run and confirm all pass**

```bash
cd packages/admin && npm test -- tests/unit/composables/useSchema.test.ts
```

Expected: all 7 tests PASS.

**Step 4: Commit**

```bash
git add packages/admin/tests/unit/composables/useSchema.test.ts
git commit -m "test(admin): unit tests for useSchema sortedProperties and caching"
```

---

### Task 5: Unit tests — useEntity

Tests for `search` early return, `list` query string construction, and `create` JSON:API body.

**Files:**
- Create: `packages/admin/tests/unit/composables/useEntity.test.ts`
- Reference: `packages/admin/app/composables/useEntity.ts`

**Step 1: Write the failing tests**

```ts
// packages/admin/tests/unit/composables/useEntity.test.ts
import { describe, it, expect, vi, beforeEach } from 'vitest'
import { useEntity } from '~/composables/useEntity'
import type { JsonApiDocument } from '~/composables/useEntity'

function makeDoc(data: any): JsonApiDocument {
  return { jsonapi: { version: '1.0' }, data }
}

describe('useEntity.search', () => {
  it('returns empty array when query is less than 2 characters', async () => {
    const mockFetch = vi.fn()
    vi.stubGlobal('$fetch', mockFetch)
    const { search } = useEntity()
    expect(await search('user', 'name', '')).toEqual([])
    expect(await search('user', 'name', 'a')).toEqual([])
    expect(mockFetch).not.toHaveBeenCalled()
  })

  it('calls $fetch with correct filter params when query is 2+ chars', async () => {
    const mockFetch = vi.fn().mockResolvedValue(makeDoc([]))
    vi.stubGlobal('$fetch', mockFetch)
    const { search } = useEntity()
    await search('user', 'name', 'jo')
    expect(mockFetch).toHaveBeenCalledWith(
      expect.stringContaining('filter[name][operator]=STARTS_WITH'),
    )
    expect(mockFetch).toHaveBeenCalledWith(expect.stringContaining('filter[name][value]=jo'))
  })
})

describe('useEntity.list', () => {
  it('calls /api/:type with no query string when no options given', async () => {
    const mockFetch = vi.fn().mockResolvedValue(makeDoc([]))
    vi.stubGlobal('$fetch', mockFetch)
    const { list } = useEntity()
    await list('node')
    expect(mockFetch).toHaveBeenCalledWith('/api/node')
  })

  it('appends page[offset] and page[limit] from query.page', async () => {
    const mockFetch = vi.fn().mockResolvedValue(makeDoc([]))
    vi.stubGlobal('$fetch', mockFetch)
    const { list } = useEntity()
    await list('node', { page: { offset: 25, limit: 10 } })
    expect(mockFetch).toHaveBeenCalledWith(
      expect.stringContaining('page[offset]=25'),
    )
    expect(mockFetch).toHaveBeenCalledWith(expect.stringContaining('page[limit]=10'))
  })

  it('returns data array and meta from response', async () => {
    const resource = { type: 'node', id: '1', attributes: { title: 'Hello' } }
    const mockFetch = vi.fn().mockResolvedValue({
      jsonapi: { version: '1.0' },
      data: [resource],
      meta: { total: 1 },
      links: {},
    })
    vi.stubGlobal('$fetch', mockFetch)
    const { list } = useEntity()
    const result = await list('node')
    expect(result.data).toEqual([resource])
    expect(result.meta).toEqual({ total: 1 })
  })
})

describe('useEntity.create', () => {
  it('sends POST with JSON:API body structure', async () => {
    const resource = { type: 'node', id: '1', attributes: { title: 'New' } }
    const mockFetch = vi.fn().mockResolvedValue(makeDoc(resource))
    vi.stubGlobal('$fetch', mockFetch)
    const { create } = useEntity()
    await create('node', { title: 'New' })
    expect(mockFetch).toHaveBeenCalledWith('/api/node', expect.objectContaining({
      method: 'POST',
      body: { data: { type: 'node', attributes: { title: 'New' } } },
    }))
  })
})
```

**Step 2: Run tests**

```bash
cd packages/admin && npm test -- tests/unit/composables/useEntity.test.ts
```

Expected: all tests PASS (implementation already exists).

**Step 3: Commit**

```bash
git add packages/admin/tests/unit/composables/useEntity.test.ts
git commit -m "test(admin): unit tests for useEntity list, search, and create"
```

---

### Task 6: Unit tests — useLanguage

**Files:**
- Create: `packages/admin/tests/unit/composables/useLanguage.test.ts`
- Reference: `packages/admin/app/composables/useLanguage.ts`

**Step 1: Write the failing tests**

```ts
// packages/admin/tests/unit/composables/useLanguage.test.ts
import { describe, it, expect } from 'vitest'
import { useLanguage } from '~/composables/useLanguage'

describe('useLanguage.t', () => {
  it('returns the English translation for a known key', () => {
    const { t } = useLanguage()
    // 'dashboard' is defined in app/i18n/en.json
    expect(t('dashboard')).not.toBe('dashboard')
    expect(typeof t('dashboard')).toBe('string')
    expect(t('dashboard').length).toBeGreaterThan(0)
  })

  it('falls back to the key itself for an unknown key', () => {
    const { t } = useLanguage()
    expect(t('__nonexistent_key_xyz__')).toBe('__nonexistent_key_xyz__')
  })

  it('interpolates replacement tokens', () => {
    const { t } = useLanguage()
    // If no key uses {token} in en.json, test the interpolation directly:
    // t() replaces {token} with the replacement value.
    // We test with a raw call: key falls back to itself, replacements applied.
    const result = t('Hello {name}', { name: 'World' })
    expect(result).toBe('Hello World')
  })
})
```

**Step 2: Run tests**

```bash
cd packages/admin && npm test -- tests/unit/composables/useLanguage.test.ts
```

Expected: all tests PASS.

**Step 3: Commit**

```bash
git add packages/admin/tests/unit/composables/useLanguage.test.ts
git commit -m "test(admin): unit tests for useLanguage t() and interpolation"
```

---

### Task 7: Component tests — NavBuilder

`NavBuilder` fetches entity types in `onMounted`, groups them, and renders nav sections. Uses `mountSuspended` + `flushPromises`.

**Files:**
- Create: `packages/admin/tests/components/layout/NavBuilder.test.ts`
- Reference: `packages/admin/app/components/layout/NavBuilder.vue`

**Step 1: Write the failing tests**

```ts
// packages/admin/tests/components/layout/NavBuilder.test.ts
import { describe, it, expect, vi } from 'vitest'
import { mountSuspended } from '@nuxt/test-utils/runtime'
import { flushPromises } from '@vue/test-utils'
import NavBuilder from '~/components/layout/NavBuilder.vue'
import { entityTypes } from '../../fixtures/entityTypes'

describe('NavBuilder', () => {
  it('renders the dashboard link always', async () => {
    vi.stubGlobal('$fetch', vi.fn().mockResolvedValue({ data: [] }))
    const wrapper = await mountSuspended(NavBuilder)
    await flushPromises()
    expect(wrapper.text()).toContain('Dashboard')
  })

  it('renders nav section headings after fetching entity types', async () => {
    vi.stubGlobal('$fetch', vi.fn().mockResolvedValue({ data: entityTypes }))
    const wrapper = await mountSuspended(NavBuilder)
    await flushPromises()
    // groupEntityTypes produces a 'people' group — its labelKey renders via t()
    // The nav section text comes from t('nav_group_people') etc.
    // Since the i18n keys exist in en.json, check for at least one section heading.
    const navSections = wrapper.findAll('.nav-section')
    expect(navSections.length).toBeGreaterThan(0)
  })

  it('renders entity type labels as nav links', async () => {
    vi.stubGlobal('$fetch', vi.fn().mockResolvedValue({ data: entityTypes }))
    const wrapper = await mountSuspended(NavBuilder)
    await flushPromises()
    expect(wrapper.text()).toContain('User')
    expect(wrapper.text()).toContain('Content')
  })

  it('shows error message when $fetch rejects', async () => {
    vi.stubGlobal('$fetch', vi.fn().mockRejectedValue(new Error('API down')))
    const wrapper = await mountSuspended(NavBuilder)
    await flushPromises()
    expect(wrapper.find('.nav-error').exists()).toBe(true)
  })
})
```

**Step 2: Run tests to verify they fail**

```bash
cd packages/admin && npm test -- tests/components/layout/NavBuilder.test.ts
```

Expected: tests fail if NavBuilder can't be imported or $fetch stub doesn't work. If the component is correct, tests should pass after fix.

**Step 3: Run and confirm all pass**

```bash
cd packages/admin && npm test -- tests/components/layout/NavBuilder.test.ts
```

Expected: all 4 tests PASS.

**Step 4: Commit**

```bash
git add packages/admin/tests/components/
git commit -m "test(admin): component tests for NavBuilder fetch and render"
```

---

### Task 8: Component tests — SchemaField

`SchemaField` dispatches to the correct widget component based on `schema['x-widget']`. Tests verify the widget mapping.

**Files:**
- Create: `packages/admin/tests/components/schema/SchemaField.test.ts`
- Reference: `packages/admin/app/components/schema/SchemaField.vue`

**Step 1: Write the failing tests**

```ts
// packages/admin/tests/components/schema/SchemaField.test.ts
import { describe, it, expect } from 'vitest'
import { mountSuspended } from '@nuxt/test-utils/runtime'
import SchemaField from '~/components/schema/SchemaField.vue'
import type { SchemaProperty } from '~/composables/useSchema'

function makeSchema(widget: string, extra: Partial<SchemaProperty> = {}): SchemaProperty {
  return { type: 'string', 'x-widget': widget, 'x-label': 'Test Field', ...extra }
}

describe('SchemaField widget dispatch', () => {
  it('renders a text input for x-widget: text', async () => {
    const wrapper = await mountSuspended(SchemaField, {
      props: { name: 'title', modelValue: '', schema: makeSchema('text') },
    })
    expect(wrapper.find('input[type="text"]').exists()).toBe(true)
  })

  it('renders a checkbox for x-widget: boolean', async () => {
    const wrapper = await mountSuspended(SchemaField, {
      props: { name: 'active', modelValue: false, schema: makeSchema('boolean') },
    })
    expect(wrapper.find('input[type="checkbox"]').exists()).toBe(true)
  })

  it('renders a select for x-widget: select', async () => {
    const wrapper = await mountSuspended(SchemaField, {
      props: {
        name: 'status',
        modelValue: '',
        schema: makeSchema('select', { enum: ['a', 'b'] }),
      },
    })
    expect(wrapper.find('select').exists()).toBe(true)
  })

  it('falls back to text input for unknown x-widget value', async () => {
    const wrapper = await mountSuspended(SchemaField, {
      props: { name: 'field', modelValue: '', schema: makeSchema('unknown_widget') },
    })
    expect(wrapper.find('input').exists()).toBe(true)
  })

  it('passes disabled=true to widget when x-access-restricted is set', async () => {
    const wrapper = await mountSuspended(SchemaField, {
      props: {
        name: 'email',
        modelValue: 'test@example.com',
        schema: makeSchema('text', { readOnly: true, 'x-access-restricted': true }),
        disabled: true,
      },
    })
    expect(wrapper.find('input').attributes('disabled')).toBeDefined()
  })
})
```

**Step 2: Run tests**

```bash
cd packages/admin && npm test -- tests/components/schema/SchemaField.test.ts
```

Expected: all tests PASS (SchemaField and widget implementations already exist).

**Step 3: Commit**

```bash
git add packages/admin/tests/components/schema/SchemaField.test.ts
git commit -m "test(admin): component tests for SchemaField widget dispatch"
```

---

### Task 9: Component tests — SchemaForm

`SchemaForm` has the most complex lifecycle: fetch schema → optionally load entity → render form → submit. Tests cover loading, error, create, and update flows.

**Files:**
- Create: `packages/admin/tests/components/schema/SchemaForm.test.ts`
- Reference: `packages/admin/app/components/schema/SchemaForm.vue`

**Step 1: Write the failing tests**

```ts
// packages/admin/tests/components/schema/SchemaForm.test.ts
import { describe, it, expect, vi } from 'vitest'
import { mountSuspended } from '@nuxt/test-utils/runtime'
import { flushPromises } from '@vue/test-utils'
import SchemaForm from '~/components/schema/SchemaForm.vue'
import { userSchema } from '../../fixtures/schemas'

const schemaResponse = { meta: { schema: userSchema } }

describe('SchemaForm loading and error states', () => {
  it('shows loading state while schema is fetching', async () => {
    // Never resolves — component stays in loading state
    vi.stubGlobal('$fetch', vi.fn().mockReturnValue(new Promise(() => {})))
    const wrapper = await mountSuspended(SchemaForm, {
      props: { entityType: 'user' },
    })
    expect(wrapper.find('.loading').exists()).toBe(true)
  })

  it('shows error state when schema fetch fails', async () => {
    vi.stubGlobal(
      '$fetch',
      vi.fn().mockRejectedValue({ message: 'Schema not found' }),
    )
    const wrapper = await mountSuspended(SchemaForm, {
      props: { entityType: 'user' },
    })
    await flushPromises()
    expect(wrapper.find('.error').exists()).toBe(true)
  })

  it('renders form fields after schema loads', async () => {
    vi.stubGlobal('$fetch', vi.fn().mockResolvedValue(schemaResponse))
    const wrapper = await mountSuspended(SchemaForm, {
      props: { entityType: 'user' },
    })
    await flushPromises()
    expect(wrapper.find('form').exists()).toBe(true)
  })
})

describe('SchemaForm submit — create mode (no entityId)', () => {
  it('emits saved event with resource on successful create', async () => {
    const resource = { type: 'user', id: '5', attributes: { name: 'alice' } }
    vi.stubGlobal(
      '$fetch',
      vi.fn()
        .mockResolvedValueOnce(schemaResponse) // schema fetch
        .mockResolvedValueOnce({ jsonapi: { version: '1.0' }, data: resource }), // create
    )
    const wrapper = await mountSuspended(SchemaForm, {
      props: { entityType: 'user' },
    })
    await flushPromises()
    await wrapper.find('form').trigger('submit')
    await flushPromises()
    expect(wrapper.emitted('saved')?.[0]).toEqual([resource])
  })

  it('emits error event when create fails', async () => {
    vi.stubGlobal(
      '$fetch',
      vi.fn()
        .mockResolvedValueOnce(schemaResponse)
        .mockRejectedValueOnce({
          data: { errors: [{ detail: 'Validation failed' }] },
        }),
    )
    const wrapper = await mountSuspended(SchemaForm, {
      props: { entityType: 'user' },
    })
    await flushPromises()
    await wrapper.find('form').trigger('submit')
    await flushPromises()
    expect(wrapper.emitted('error')?.[0]).toEqual(['Validation failed'])
  })
})

describe('SchemaForm submit — edit mode (with entityId)', () => {
  it('loads existing entity attributes into form', async () => {
    const resource = { type: 'user', id: '3', attributes: { name: 'bob' } }
    vi.stubGlobal(
      '$fetch',
      vi.fn()
        .mockResolvedValueOnce(schemaResponse)      // schema
        .mockResolvedValueOnce({ jsonapi: { version: '1.0' }, data: resource }), // get
    )
    const wrapper = await mountSuspended(SchemaForm, {
      props: { entityType: 'user', entityId: '3' },
    })
    await flushPromises()
    // The name field should be pre-populated
    const nameInput = wrapper.find('input[type="text"]')
    expect((nameInput.element as HTMLInputElement).value).toBe('bob')
  })
})
```

**Step 2: Run tests**

```bash
cd packages/admin && npm test -- tests/components/schema/SchemaForm.test.ts
```

Expected: tests may require tweaking mock call order depending on how `$fetch` is called internally. Adjust `mockResolvedValueOnce` order if needed.

**Step 3: Confirm all pass**

```bash
cd packages/admin && npm test -- tests/components/schema/SchemaForm.test.ts
```

Expected: all tests PASS.

**Step 4: Commit**

```bash
git add packages/admin/tests/components/schema/SchemaForm.test.ts
git commit -m "test(admin): component tests for SchemaForm loading, create, and edit flows"
```

---

### Task 10: Widget component tests

Three widgets: TextInput (text input + label), Toggle (checkbox), Select (enum options).

**Files:**
- Create: `packages/admin/tests/components/widgets/TextInput.test.ts`
- Create: `packages/admin/tests/components/widgets/Toggle.test.ts`
- Create: `packages/admin/tests/components/widgets/Select.test.ts`
- Reference: `packages/admin/app/components/widgets/TextInput.vue`, `Toggle.vue`, `Select.vue`

**Step 1: Write TextInput tests**

```ts
// packages/admin/tests/components/widgets/TextInput.test.ts
import { describe, it, expect } from 'vitest'
import { mountSuspended } from '@nuxt/test-utils/runtime'
import TextInput from '~/components/widgets/TextInput.vue'

describe('TextInput', () => {
  it('renders the label', async () => {
    const wrapper = await mountSuspended(TextInput, {
      props: { modelValue: '', label: 'Username' },
    })
    expect(wrapper.text()).toContain('Username')
  })

  it('emits update:modelValue on user input', async () => {
    const wrapper = await mountSuspended(TextInput, {
      props: { modelValue: '', label: 'Username' },
    })
    await wrapper.find('input').setValue('alice')
    expect(wrapper.emitted('update:modelValue')?.[0]).toEqual(['alice'])
  })

  it('renders the input as disabled when disabled=true', async () => {
    const wrapper = await mountSuspended(TextInput, {
      props: { modelValue: '', label: 'Username', disabled: true },
    })
    expect(wrapper.find('input').attributes('disabled')).toBeDefined()
  })

  it('shows required asterisk when required=true', async () => {
    const wrapper = await mountSuspended(TextInput, {
      props: { modelValue: '', label: 'Username', required: true },
    })
    expect(wrapper.text()).toContain('*')
  })

  it('sets input type to email for x-widget: email', async () => {
    const wrapper = await mountSuspended(TextInput, {
      props: {
        modelValue: '',
        label: 'Email',
        schema: { type: 'string', 'x-widget': 'email' },
      },
    })
    expect(wrapper.find('input').attributes('type')).toBe('email')
  })
})
```

**Step 2: Write Toggle tests**

```ts
// packages/admin/tests/components/widgets/Toggle.test.ts
import { describe, it, expect } from 'vitest'
import { mountSuspended } from '@nuxt/test-utils/runtime'
import Toggle from '~/components/widgets/Toggle.vue'

describe('Toggle', () => {
  it('renders as a checkbox', async () => {
    const wrapper = await mountSuspended(Toggle, {
      props: { modelValue: false, label: 'Active' },
    })
    expect(wrapper.find('input[type="checkbox"]').exists()).toBe(true)
  })

  it('reflects modelValue as checked state', async () => {
    const wrapper = await mountSuspended(Toggle, {
      props: { modelValue: true, label: 'Active' },
    })
    expect((wrapper.find('input').element as HTMLInputElement).checked).toBe(true)
  })

  it('emits update:modelValue with new boolean on change', async () => {
    const wrapper = await mountSuspended(Toggle, {
      props: { modelValue: false, label: 'Active' },
    })
    await wrapper.find('input').setValue(true)
    expect(wrapper.emitted('update:modelValue')?.[0]).toEqual([true])
  })

  it('is disabled when disabled=true', async () => {
    const wrapper = await mountSuspended(Toggle, {
      props: { modelValue: false, label: 'Active', disabled: true },
    })
    expect(wrapper.find('input').attributes('disabled')).toBeDefined()
  })
})
```

**Step 3: Write Select tests**

```ts
// packages/admin/tests/components/widgets/Select.test.ts
import { describe, it, expect } from 'vitest'
import { mountSuspended } from '@nuxt/test-utils/runtime'
import Select from '~/components/widgets/Select.vue'

describe('Select', () => {
  const schema = {
    type: 'string',
    enum: ['active', 'blocked'],
    'x-enum-labels': { active: 'Active', blocked: 'Blocked' },
  }

  it('renders an option for each enum value', async () => {
    const wrapper = await mountSuspended(Select, {
      props: { modelValue: '', label: 'Status', schema },
    })
    const options = wrapper.findAll('option')
    // includes the "-- Select --" placeholder + 2 enum options
    expect(options.length).toBe(3)
    expect(options[1].text()).toBe('Active')
    expect(options[2].text()).toBe('Blocked')
  })

  it('emits update:modelValue on selection change', async () => {
    const wrapper = await mountSuspended(Select, {
      props: { modelValue: '', label: 'Status', schema },
    })
    await wrapper.find('select').setValue('blocked')
    expect(wrapper.emitted('update:modelValue')?.[0]).toEqual(['blocked'])
  })

  it('is disabled when disabled=true', async () => {
    const wrapper = await mountSuspended(Select, {
      props: { modelValue: '', label: 'Status', schema, disabled: true },
    })
    expect(wrapper.find('select').attributes('disabled')).toBeDefined()
  })
})
```

**Step 4: Run all widget tests**

```bash
cd packages/admin && npm test -- tests/components/widgets/
```

Expected: all tests PASS.

**Step 5: Run full test suite**

```bash
cd packages/admin && npm test
```

Expected: all tests PASS, zero failures.

**Step 6: Commit**

```bash
git add packages/admin/tests/components/widgets/
git commit -m "test(admin): widget component tests for TextInput, Toggle, Select"
```

---

### Task 11: Playwright E2E setup and fixtures

**Files:**
- Create: `packages/admin/e2e/fixtures/routes.ts`

**Step 1: Create Playwright route helpers**

```ts
// packages/admin/e2e/fixtures/routes.ts
import type { Page } from '@playwright/test'
import { entityTypes } from '../../tests/fixtures/entityTypes'
import { userSchema } from '../../tests/fixtures/schemas'

export async function mockEntityTypesRoute(page: Page) {
  await page.route('**/api/entity-types', (route) =>
    route.fulfill({ json: { data: entityTypes } }),
  )
}

export async function mockSchemaRoute(page: Page, entityType = 'user') {
  await page.route(`**/api/schema/${entityType}`, (route) =>
    route.fulfill({ json: { meta: { schema: userSchema } } }),
  )
}

export async function mockEntityListRoute(page: Page, entityType = 'user') {
  await page.route(`**/api/${entityType}`, (route) =>
    route.fulfill({
      json: {
        jsonapi: { version: '1.0' },
        data: [],
        meta: { total: 0 },
        links: {},
      },
    }),
  )
}

export async function mockEntityCreateRoute(page: Page, entityType = 'user') {
  await page.route(`**/api/${entityType}`, async (route) => {
    if (route.request().method() === 'POST') {
      await route.fulfill({
        json: {
          jsonapi: { version: '1.0' },
          data: { type: entityType, id: '99', attributes: {} },
        },
      })
    } else {
      await route.continue()
    }
  })
}
```

**Step 2: Verify playwright.config.ts can find the dev server**

```bash
cd packages/admin && npm run dev &
sleep 5 && curl -s http://localhost:3000 | head -5
kill %1
```

Expected: HTML response from Nuxt dev server.

**Step 3: Commit**

```bash
git add packages/admin/e2e/
git commit -m "test(admin): add Playwright E2E route fixtures"
```

---

### Task 12: E2E tests — dashboard, navigation, entity form

**Files:**
- Create: `packages/admin/e2e/dashboard.spec.ts`
- Create: `packages/admin/e2e/navigation.spec.ts`
- Create: `packages/admin/e2e/entity-form.spec.ts`

**Step 1: Write dashboard E2E tests**

```ts
// packages/admin/e2e/dashboard.spec.ts
import { test, expect } from '@playwright/test'
import { mockEntityTypesRoute } from './fixtures/routes'

test.describe('Dashboard', () => {
  test.beforeEach(async ({ page }) => {
    await mockEntityTypesRoute(page)
  })

  test('renders entity type cards with labels', async ({ page }) => {
    await page.goto('/')
    await expect(page.getByText('User')).toBeVisible()
    await expect(page.getByText('Content')).toBeVisible()
  })

  test('each card links to the entity type route', async ({ page }) => {
    await page.goto('/')
    const userCard = page.locator('a[href="/user"]')
    await expect(userCard).toBeVisible()
  })

  test('clicking a card navigates to the entity type list', async ({ page }) => {
    // Mock the entity list API for the user type
    await page.route('**/api/user', (route) =>
      route.fulfill({
        json: { jsonapi: { version: '1.0' }, data: [], meta: { total: 0 }, links: {} },
      }),
    )
    await page.goto('/')
    await page.locator('a[href="/user"]').first().click()
    await expect(page).toHaveURL('/user')
  })
})
```

**Step 2: Write navigation E2E tests**

```ts
// packages/admin/e2e/navigation.spec.ts
import { test, expect } from '@playwright/test'
import { mockEntityTypesRoute } from './fixtures/routes'

test.describe('Navigation', () => {
  test.beforeEach(async ({ page }) => {
    await mockEntityTypesRoute(page)
    await page.goto('/')
  })

  test('renders the Dashboard link', async ({ page }) => {
    await expect(page.locator('nav').getByText('Dashboard')).toBeVisible()
  })

  test('renders grouped nav section headings', async ({ page }) => {
    // At least one section heading should appear (e.g., "People")
    const sections = page.locator('.nav-section')
    await expect(sections.first()).toBeVisible()
  })

  test('renders entity type labels in the nav', async ({ page }) => {
    await expect(page.locator('nav').getByText('User')).toBeVisible()
  })
})
```

**Step 3: Write entity form E2E tests**

```ts
// packages/admin/e2e/entity-form.spec.ts
import { test, expect } from '@playwright/test'
import {
  mockEntityTypesRoute,
  mockSchemaRoute,
  mockEntityCreateRoute,
} from './fixtures/routes'

test.describe('Entity create form', () => {
  test.beforeEach(async ({ page }) => {
    await mockEntityTypesRoute(page)
    await mockSchemaRoute(page, 'user')
    await mockEntityCreateRoute(page, 'user')
  })

  test('renders form fields from schema', async ({ page }) => {
    await page.goto('/user/create')
    // Username field should render (name property from userSchema fixture)
    await expect(page.getByLabel('Username')).toBeVisible()
  })

  test('shows disabled field for x-access-restricted properties', async ({ page }) => {
    await page.goto('/user/create')
    // Email is x-access-restricted in userSchema fixture
    const emailInput = page.getByLabel('Email')
    await expect(emailInput).toBeDisabled()
  })

  test('submits form and handles success', async ({ page }) => {
    await page.goto('/user/create')
    await page.getByLabel('Username').fill('testuser')
    await page.getByRole('button', { name: /create/i }).click()
    // After successful POST, expect navigation away from create page
    await expect(page).not.toHaveURL('/user/create')
  })
})
```

**Step 4: Run E2E tests**

```bash
cd packages/admin && npm run test:e2e
```

Expected: Playwright starts `nuxt dev`, runs 3 spec files. Tests may need minor URL or selector adjustments based on actual rendered output — fix as needed.

> **If nuxt dev takes too long to start:** Increase the `webServer.timeout` in `playwright.config.ts` from 120s to 180s.

**Step 5: Run full suite + E2E**

```bash
cd packages/admin && npm test && npm run test:e2e
```

Expected: all Vitest tests pass, all E2E tests pass.

**Step 6: Final commit**

```bash
git add packages/admin/e2e/
git commit -m "test(admin): E2E tests for dashboard, navigation, and entity create form"
```

---

## Verification

Run the complete test suite:

```bash
cd packages/admin
npm run test:coverage
npm run test:e2e
```

Expected coverage output shows `app/composables/*.ts` and `app/components/**/*.vue` files covered. E2E: all 3 specs pass in Chromium.

Also update `CLAUDE.md` to remove the note "no test framework for admin SPA; build verifies TypeScript compilation" and replace with the actual test commands.
