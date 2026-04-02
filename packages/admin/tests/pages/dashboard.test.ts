import type { CatalogEntry } from '~/contracts'
import { describe, it, expect, beforeEach, vi } from 'vitest'
import { mountSuspended } from '@nuxt/test-utils/runtime'
import { flushPromises } from '@vue/test-utils'
import Dashboard from '~/pages/index.vue'

const { catalogRef, listSpy } = vi.hoisted(() => {
  const { ref } = require('vue') as typeof import('vue')
  return {
    catalogRef: ref<CatalogEntry[]>([]),
    listSpy: vi.fn(),
  }
})

vi.mock('~/composables/useAdmin', () => ({
  useAdmin: () => ({
    catalog: catalogRef.value,
  }),
}))

vi.mock('~/composables/useEntity', () => ({
  useEntity: () => ({
    list: listSpy,
  }),
}))

describe('Dashboard onboarding', () => {
  beforeEach(() => {
    catalogRef.value = []
    listSpy.mockReset()
  })

  it('shows onboarding prompt when no content types exist', async () => {
    catalogRef.value = [
      {
        id: 'node_type',
        label: 'Content Type',
        capabilities: { list: true, get: true, create: true, update: true, delete: true, schema: true },
        fields: [],
        actions: [],
      },
      {
        id: 'note',
        label: 'Note',
        capabilities: { list: true, get: true, create: true, update: true, delete: true, schema: true },
        fields: [],
        actions: [],
      },
    ]
    listSpy.mockResolvedValue({
      data: [],
      meta: { total: 0 },
    })

    const wrapper = await mountSuspended(Dashboard)
    await flushPromises()

    expect(listSpy).toHaveBeenCalledWith('node_type', { page: { offset: 0, limit: 1 } })
    expect(wrapper.text()).toContain('Get started with your first content type')
    expect(wrapper.text()).toContain('Use Note (built-in)')
    expect(wrapper.findAll('a').find(link => link.text().includes('Use Note'))?.attributes('href')).toContain('/note/create')
    expect(wrapper.findAll('a').find(link => link.text().includes('Create custom type'))?.attributes('href')).toContain('/node_type/create')
  })

  it('falls back to the first create-capable type when node_type is not creatable', async () => {
    catalogRef.value = [
      {
        id: 'node_type',
        label: 'Content Type',
        capabilities: { list: true, get: true, create: false, update: true, delete: true, schema: true },
        fields: [],
        actions: [],
      },
      {
        id: 'media',
        label: 'Media',
        capabilities: { list: true, get: true, create: true, update: true, delete: true, schema: true },
        fields: [],
        actions: [],
      },
    ]
    listSpy.mockResolvedValue({
      data: [],
      meta: { total: 0 },
    })

    const wrapper = await mountSuspended(Dashboard)
    await flushPromises()

    expect(wrapper.findAll('a').find(link => link.text().includes('Create custom type'))?.attributes('href')).toContain('/media/create')
  })

  it('falls back to root when the note entity type is absent', async () => {
    catalogRef.value = [
      {
        id: 'node_type',
        label: 'Content Type',
        capabilities: { list: true, get: true, create: true, update: true, delete: true, schema: true },
        fields: [],
        actions: [],
      },
    ]
    listSpy.mockResolvedValue({
      data: [],
      meta: { total: 0 },
    })

    const wrapper = await mountSuspended(Dashboard)
    await flushPromises()

    expect(wrapper.findAll('a').find(link => link.text().includes('Use Note'))?.attributes('href')).toBe('/admin/')
  })

  it('probes the first listable entity type when node_type is absent', async () => {
    catalogRef.value = [
      {
        id: 'user',
        label: 'User',
        capabilities: { list: false, get: true, create: false, update: true, delete: false, schema: true },
        fields: [],
        actions: [],
      },
      {
        id: 'media',
        label: 'Media',
        capabilities: { list: true, get: true, create: true, update: true, delete: true, schema: true },
        fields: [],
        actions: [],
      },
    ]
    listSpy.mockResolvedValue({
      data: [],
      meta: { total: 0 },
    })

    const wrapper = await mountSuspended(Dashboard)
    await flushPromises()

    expect(listSpy).toHaveBeenCalledWith('media', { page: { offset: 0, limit: 1 } })
    expect(wrapper.text()).toContain('Get started with your first content type')
  })
})
