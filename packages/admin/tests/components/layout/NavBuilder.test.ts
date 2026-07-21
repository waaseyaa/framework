// packages/admin/tests/components/layout/NavBuilder.test.ts
import type { CatalogEntry } from '~/contracts'
import { describe, it, expect, beforeEach, vi } from 'vitest'
import { mountSuspended } from '@nuxt/test-utils/runtime'
import NavBuilder from '~/components/layout/NavBuilder.vue'

const { catalogRef, featuresRef, uiRef, runActionSpy } = vi.hoisted(() => {
  const { ref } = require('vue') as typeof import('vue')
  return {
    catalogRef: ref<CatalogEntry[]>([]),
    featuresRef: ref<Record<string, boolean>>({ mcp: true }),
    uiRef: ref<{ headerLinks: unknown[]; sidebarItems: unknown[]; navigationMode?: 'full' | 'catalog-only' }>({
      headerLinks: [],
      sidebarItems: [],
    }),
    runActionSpy: vi.fn(),
  }
})

vi.mock('~/composables/useAdmin', () => ({
  useAdmin: () => ({
    catalog: catalogRef.value,
    features: featuresRef.value,
    ui: uiRef.value,
  }),
}))

vi.mock('~/composables/useEntity', () => ({
  useEntity: () => ({
    runAction: runActionSpy,
  }),
}))

const defaultCaps = { list: false, get: false, create: false, update: false, delete: false, schema: false }

function entry(overrides: Partial<CatalogEntry> & Pick<CatalogEntry, 'id' | 'label'>): CatalogEntry {
  const { id, label, ...rest } = overrides
  return {
    id,
    label,
    capabilities: defaultCaps,
    fields: [],
    actions: [],
    ...rest,
  }
}

describe('NavBuilder', () => {
  beforeEach(() => {
    runActionSpy.mockReset()
    featuresRef.value = { mcp: true }
    uiRef.value = { headerLinks: [], sidebarItems: [] }
    catalogRef.value = [
      entry({ id: 'user', label: 'User', group: 'system' }),
      entry({ id: 'node', label: 'Content', group: 'content' }),
    ]
  })

  it('renders the dashboard link always', async () => {
    const wrapper = await mountSuspended(NavBuilder)
    expect(wrapper.text()).toContain('Dashboard')
  })

  it('renders nav section headings from catalog', async () => {
    const wrapper = await mountSuspended(NavBuilder)
    const navSections = wrapper.findAll('.nav-section')
    expect(navSections.length).toBeGreaterThan(0)
  })

  it('renders entity type labels as nav links', async () => {
    const wrapper = await mountSuspended(NavBuilder)
    expect(wrapper.text()).toContain('User')
    expect(wrapper.text()).toContain('Content')
    expect(wrapper.findAll('.nav-item').length).toBeGreaterThan(0)
    expect(wrapper.findAll('.nav-item').every(link => link.classes().includes('touch-target'))).toBe(true)
  })

  it('renders the dashboard + Operations section when the catalog is empty', async () => {
    catalogRef.value = []

    const wrapper = await mountSuspended(NavBuilder)

    expect(wrapper.text()).toContain('Dashboard')
    // Two always-present static sections when the catalog is empty:
    //  - "Operations" (M4B/M4C/M5D): /workflows, /queue, /scheduler,
    //    /notifications, /mercure/monitor.
    //  - "Governance" (classification-retention-engine-01KSEFTH WP04):
    //    /classification/policies.
    expect(wrapper.text()).toContain('Operations')
    expect(wrapper.text()).toContain('Governance')
    expect(wrapper.find('[data-testid="nav-queue"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="nav-scheduler"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="nav-notifications"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="nav-classification-policies"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="nav-mcp-tools"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="nav-mcp-server-config"]').exists()).toBe(true)
    // Static sections when the catalog is empty (M5C MCP section ships
    // alongside Operations + Governance):
    //  - MCP (M5C): /mcp/tools, /mcp/server-config
    //  - Operations: /workflows, /queue, /scheduler, /notifications, /mercure/monitor
    //  - Governance: /classification/policies
    // 3 sections; links: dashboard + 2 (MCP) + 5 (Operations) + 1 (Governance) = 9.
    expect(wrapper.findAll('.nav-section')).toHaveLength(3)
    expect(wrapper.findAll('a')).toHaveLength(9)
  })

  it('omits the MCP menu group when the MCP package is not installed', async () => {
    featuresRef.value = { mcp: false }

    const wrapper = await mountSuspended(NavBuilder)

    expect(wrapper.find('[data-testid="nav-section-mcp"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="nav-mcp-tools"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="nav-mcp-server-config"]').exists()).toBe(false)
  })

  it('renders the pipeline link when the catalog entry declares board-config', async () => {
    catalogRef.value = [
      entry({
        id: 'node',
        label: 'Content',
        group: 'content',
        actions: [{ id: 'board-config', label: 'Board Config', scope: 'collection' }],
      }),
    ]

    const wrapper = await mountSuspended(NavBuilder)

    expect(wrapper.text()).toContain('Content Pipeline')
    expect(wrapper.findAll('a').some(link => link.text() === 'Content Pipeline')).toBe(true)
  })

  it('does not render the pipeline link when board-config is absent', async () => {
    catalogRef.value = [
      entry({
        id: 'node',
        label: 'Content',
        group: 'content',
        actions: [{ id: 'delete', label: 'Delete', scope: 'entity', dangerous: true }],
      }),
    ]

    const wrapper = await mountSuspended(NavBuilder)

    expect(wrapper.text()).not.toContain('Content Pipeline')
    expect(wrapper.findAll('a').some(link => link.text() === 'Content Pipeline')).toBe(false)
  })

  it('renders custom sidebar items from ui before catalog groups', async () => {
    uiRef.value = {
      headerLinks: [],
      sidebarItems: [
        { id: 'app-home', label: 'Site home', href: 'https://example.com', external: true },
        { id: 'in-app', label: 'Reports', href: '/reports' },
      ],
    }
    catalogRef.value = [
      entry({ id: 'user', label: 'User', group: 'system' }),
    ]

    const wrapper = await mountSuspended(NavBuilder)

    const html = wrapper.html()
    expect(html).toContain('Site home')
    expect(html).toContain('Reports')
    expect(html).toContain('Shortcuts')
    expect(wrapper.text().indexOf('Shortcuts')).toBeLessThan(wrapper.text().indexOf('User'))
  })

  it('uses i18n for custom section when group is a nav_* key', async () => {
    uiRef.value = {
      headerLinks: [],
      sidebarItems: [
        { id: 'x', label: 'Media link', href: '/m', group: 'nav_group_media' },
      ],
    }
    catalogRef.value = []

    const wrapper = await mountSuspended(NavBuilder)

    expect(wrapper.text()).toContain('Media')
    expect(wrapper.text()).toContain('Media link')
  })

  it('does not perform mount-time action execution to decide pipeline visibility', async () => {
    catalogRef.value = [
      entry({
        id: 'node',
        label: 'Content',
        group: 'content',
        actions: [{ id: 'board-config', label: 'Board Config', scope: 'collection' }],
      }),
    ]

    await mountSuspended(NavBuilder)

    expect(runActionSpy).not.toHaveBeenCalled()
  })

  it('catalog-only renders only catalog navigation without dashboard, host, or operational links', async () => {
    uiRef.value = {
      headerLinks: [],
      sidebarItems: [{ id: 'reports', label: 'Operational reports', href: '/reports' }],
      navigationMode: 'catalog-only',
    }
    catalogRef.value = [
      entry({ id: 'node', label: 'Content', group: 'content' }),
      entry({ id: 'media', label: 'Media', group: 'media' }),
    ]

    const wrapper = await mountSuspended(NavBuilder)

    expect(wrapper.text()).toContain('Content')
    expect(wrapper.text()).toContain('Media')
    expect(wrapper.text()).not.toContain('Dashboard')
    expect(wrapper.text()).not.toContain('Operational reports')
    expect(wrapper.find('[data-testid="nav-mcp-tools"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="nav-queue"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="nav-scheduler"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="nav-notifications"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="nav-mercure-monitor"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="nav-classification-policies"]').exists()).toBe(false)
    expect(wrapper.text()).not.toContain('Workflows')
  })
})
