import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises } from '@vue/test-utils'
import { mountSuspended } from '@nuxt/test-utils/runtime'
import type { EntityResource } from '~/contracts/transport'
import EntityAutocomplete from '~/components/widgets/EntityAutocomplete.vue'

const search = vi.fn()
const list = vi.fn()
const getEntity = vi.fn()

vi.mock('~/composables/useEntity', () => ({
  useEntity: () => ({ list, search }),
}))

vi.mock('~/composables/useAdmin', () => ({
  useAdmin: () => ({ getEntity }),
}))

vi.mock('~/composables/useLanguage', () => ({
  useLanguage: () => ({ t: (key: string) => key }),
}))

const mediaReference = {
  labelField: 'name',
  search: { field: 'name', operator: 'STARTS_WITH' as const },
  sort: { field: 'name', direction: 'ASC' as const },
}

function mediaResult(id = '7', name = 'Annual report'): EntityResource {
  return { type: 'media', id, attributes: { name } }
}

async function mountWidget(schema: Record<string, unknown> = { 'x-target-type': 'media' }) {
  return mountSuspended(EntityAutocomplete, {
    props: {
      modelValue: '',
      label: 'Attachments',
      schema: { type: 'string', ...schema },
    },
  })
}

async function enterQuery(wrapper: Awaited<ReturnType<typeof mountWidget>>, value: string) {
  await wrapper.get('input[role="combobox"]').setValue(value)
  await vi.advanceTimersByTimeAsync(250)
  await flushPromises()
}

describe('EntityAutocomplete authoritative reference search', () => {
  beforeEach(() => {
    vi.useFakeTimers()
    search.mockReset()
    list.mockReset()
    getEntity.mockReset()
    getEntity.mockReturnValue({ id: 'media', reference: mediaReference })
  })

  afterEach(() => {
    vi.useRealTimers()
  })

  it('uses media name metadata rather than guessing title', async () => {
    search.mockResolvedValue([mediaResult()])
    const wrapper = await mountWidget()

    await enterQuery(wrapper, 'Ann')

    expect(search).toHaveBeenCalledWith('media', 'name', 'Ann', 10, 'STARTS_WITH', mediaReference.sort)
    expect(wrapper.get('[role="option"]').text()).toBe('Annual report')
  })

  it('applies schema-owned exact filters for a governed media picker', async () => {
    list.mockResolvedValue({ data: [mediaResult()], meta: { total: 1, offset: 0, limit: 10 } })
    const wrapper = await mountWidget({
      'x-target-type': 'media',
      'x-target-filter': { type: 'image' },
    })

    await enterQuery(wrapper, 'Ann')

    expect(search).not.toHaveBeenCalled()
    expect(list).toHaveBeenCalledWith('media', {
      filter: {
        name: { operator: 'STARTS_WITH', value: 'Ann' },
        type: { operator: 'EQUALS', value: 'image' },
      },
      sort: 'name',
      page: { offset: 0, limit: 10 },
    })
    expect(wrapper.get('[role="option"]').text()).toBe('Annual report')
  })

  it('honours catalog-owned contains search with governed picker filters', async () => {
    getEntity.mockReturnValue({
      id: 'media',
      reference: {
        ...mediaReference,
        search: { field: 'name', operator: 'CONTAINS' as const },
      },
    })
    list.mockResolvedValue({ data: [mediaResult()], meta: { total: 1, offset: 0, limit: 10 } })
    const wrapper = await mountWidget({
      'x-target-type': 'media',
      'x-target-filter': { bundle: 'image' },
    })

    await enterQuery(wrapper, 'report')

    expect(list).toHaveBeenCalledWith('media', {
      filter: {
        name: { operator: 'CONTAINS', value: 'report' },
        bundle: { operator: 'EQUALS', value: 'image' },
      },
      sort: 'name',
      page: { offset: 0, limit: 10 },
    })
  })

  it.each([
    ['missing', undefined],
    ['malformed', { labelField: 4, search: { field: 'name', operator: 'STARTS_WITH' } }],
    ['non-searchable', { labelField: 'name', search: null, sort: null }],
  ])('fails closed when catalog reference metadata is %s', async (_case, reference) => {
    getEntity.mockReturnValue({ id: 'media', reference })
    const wrapper = await mountWidget()

    await enterQuery(wrapper, 'Ann')

    expect(search).not.toHaveBeenCalled()
    expect(wrapper.get('[role="alert"]').text()).toBe('autocomplete_unavailable')
    expect(wrapper.find('[role="option"]').exists()).toBe(false)
  })

  it('programmatically associates its visible label and exposes loading and empty states', async () => {
    let resolve!: (value: EntityResource[]) => void
    search.mockReturnValue(new Promise<EntityResource[]>((done) => { resolve = done }))
    const wrapper = await mountWidget()
    const input = wrapper.get('input[role="combobox"]')

    expect(wrapper.get('label').attributes('for')).toBe(input.attributes('id'))
    await input.setValue('zz')
    await vi.advanceTimersByTimeAsync(250)
    expect(wrapper.get('[role="status"]').text()).toBe('autocomplete_loading')

    resolve([])
    await flushPromises()
    expect(wrapper.get('[role="status"]').text()).toBe('autocomplete_no_results')
  })

  it('uses separate 44px target contracts for the input, clear control, and options', async () => {
    search.mockResolvedValue([mediaResult()])
    const wrapper = await mountWidget()
    const input = wrapper.get('input[role="combobox"]')

    expect(input.classes()).toContain('touch-target')
    await enterQuery(wrapper, 'Ann')

    const clear = wrapper.get('.autocomplete-clear')
    const option = wrapper.get('[role="option"]')
    expect(clear.classes()).toContain('touch-target')
    expect(option.classes()).toContain('touch-target')
    expect(clear.element.parentElement).not.toBe(input.element.parentElement)

    await clear.trigger('click')
    expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual([''])
    expect(document.activeElement).not.toBe(option.element)
  })

  it.each([
    [403, 'autocomplete_forbidden'],
    [404, 'autocomplete_not_found'],
    [500, 'autocomplete_server_error'],
  ])('announces HTTP %s without exposing backend detail', async (status, message) => {
    search.mockRejectedValue({ status, detail: 'private_document id=91 secret path' })
    const wrapper = await mountWidget()

    await enterQuery(wrapper, 'Ann')

    expect(wrapper.get('[role="alert"]').text()).toBe(message)
    expect(wrapper.text()).not.toContain('private_document')
    expect(wrapper.text()).not.toContain('91')
  })

  it('distinguishes malformed and network failures without exposing raw detail', async () => {
    search.mockResolvedValueOnce([{ type: 'media', id: '91', attributes: {} }])
    const malformed = await mountWidget()
    await enterQuery(malformed, 'bad')
    expect(malformed.get('[role="alert"]').text()).toBe('autocomplete_malformed_response')
    expect(malformed.text()).not.toContain('91')

    search.mockRejectedValueOnce(new TypeError('network internals'))
    const network = await mountWidget()
    await enterQuery(network, 'net')
    expect(network.get('[role="alert"]').text()).toBe('autocomplete_network_error')
    expect(network.text()).not.toContain('network internals')
  })

  it('clears stale results as soon as the query changes', async () => {
    search.mockResolvedValue([mediaResult()])
    const wrapper = await mountWidget()
    await enterQuery(wrapper, 'Ann')
    expect(wrapper.find('[role="option"]').exists()).toBe(true)

    await wrapper.get('input').setValue('Different')

    expect(wrapper.find('[role="option"]').exists()).toBe(false)
  })

  it('ignores an older out-of-order response and does not issue duplicate searches', async () => {
    let resolveOld!: (value: EntityResource[]) => void
    let resolveNew!: (value: EntityResource[]) => void
    search
      .mockReturnValueOnce(new Promise<EntityResource[]>((done) => { resolveOld = done }))
      .mockReturnValueOnce(new Promise<EntityResource[]>((done) => { resolveNew = done }))
    const wrapper = await mountWidget()

    await wrapper.get('input').setValue('old')
    await vi.advanceTimersByTimeAsync(250)
    await wrapper.get('input').setValue('new')
    await vi.advanceTimersByTimeAsync(250)
    resolveNew([mediaResult('2', 'New result')])
    await flushPromises()
    resolveOld([mediaResult('1', 'Old result')])
    await flushPromises()

    expect(search).toHaveBeenCalledTimes(2)
    expect(wrapper.get('[role="option"]').text()).toBe('New result')
  })

  it('supports keyboard selection with active-descendant semantics', async () => {
    search.mockResolvedValue([mediaResult()])
    const wrapper = await mountWidget()
    await enterQuery(wrapper, 'Ann')
    const input = wrapper.get('input[role="combobox"]')

    await input.trigger('keydown', { key: 'ArrowDown' })
    expect(input.attributes('aria-activedescendant')).toBe(wrapper.get('[role="option"]').attributes('id'))
    await input.trigger('keydown', { key: 'Enter' })

    expect(wrapper.emitted('update:modelValue')?.[0]).toEqual(['7'])
  })

  it('never falls back to a resource id when the authoritative label is absent', async () => {
    search.mockResolvedValue([{ type: 'media', id: 'private-91', attributes: {} }])
    const wrapper = await mountWidget()

    await enterQuery(wrapper, 'Ann')

    expect(wrapper.find('[role="option"]').exists()).toBe(false)
    expect(wrapper.get('[role="alert"]').text()).toBe('autocomplete_malformed_response')
    expect(wrapper.text()).not.toContain('private-91')
  })

  it('authors multi-value references without duplicate IDs and supports accessible removal', async () => {
    search.mockResolvedValue([mediaResult()])
    const wrapper = await mountSuspended(EntityAutocomplete, {
      props: {
        modelValue: ['3'],
        label: 'Attachments',
        schema: { type: 'array', 'x-target-type': 'media', 'x-cardinality': -1 },
      },
    })

    await enterQuery(wrapper as Awaited<ReturnType<typeof mountWidget>>, 'Ann')
    await wrapper.get('[role="option"]').trigger('mousedown')
    expect(wrapper.emitted('update:modelValue')?.[0]).toEqual([['3', '7']])

    await enterQuery(wrapper as Awaited<ReturnType<typeof mountWidget>>, 'Ann')
    expect(wrapper.find('[role="option"]').exists()).toBe(false)
    expect(wrapper.emitted('update:modelValue')?.[1]).toBeUndefined()

    const remove = wrapper.get('button[aria-label="autocomplete_remove"]')
    await remove.trigger('click')
    expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual([['7']])
  })
})
