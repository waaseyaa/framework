// packages/admin/tests/components/layout/NavBuilder.test.ts
import type { CatalogEntry } from '~/contracts'
import { describe, it, expect, beforeEach, vi } from 'vitest'
import { mountSuspended } from '@nuxt/test-utils/runtime'
import NavBuilder from '~/components/layout/NavBuilder.vue'

const { catalogRef, runActionSpy } = vi.hoisted(() => {
  const { ref } = require('vue') as typeof import('vue')
  return {
    catalogRef: ref<CatalogEntry[]>([]),
    runActionSpy: vi.fn(),
  }
})

vi.mock('~/composables/useAdmin', () => ({
  useAdmin: () => ({
    catalog: catalogRef.value,
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
  })

  it('renders only the dashboard link when the catalog is empty', async () => {
    catalogRef.value = []

    const wrapper = await mountSuspended(NavBuilder)

    expect(wrapper.text()).toContain('Dashboard')
    expect(wrapper.findAll('.nav-section')).toHaveLength(0)
    expect(wrapper.findAll('a')).toHaveLength(1)
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
})
