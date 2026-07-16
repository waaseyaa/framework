import { describe, expect, it, vi } from 'vitest'
import { mountSuspended } from '@nuxt/test-utils/runtime'
import { ref } from 'vue'
import SchemaView from '~/components/schema/SchemaView.vue'

vi.mock('~/composables/useSchema', () => ({
  useSchema: () => ({
    error: ref(null),
    fetch: vi.fn().mockResolvedValue(undefined),
    sortedProperties: () => [
      ['title', { type: 'string', 'x-label': 'Title' }],
      ['summary', { type: 'string', 'x-label': 'Summary' }],
    ],
  }),
}))

vi.mock('~/composables/useEntity', () => ({
  useEntity: () => ({
    get: vi.fn().mockResolvedValue({ id: '1', attributes: { title: 'Example', summary: '' } }),
  }),
}))

vi.mock('~/composables/useLanguage', () => ({
  useLanguage: () => ({ t: (key: string) => key }),
}))

describe('SchemaView target contract', () => {
  it('uses the shared target utility for empty-field disclosure', async () => {
    const wrapper = await mountSuspended(SchemaView, {
      props: { entityType: 'node', entityId: '1' },
    })
    await vi.waitFor(() => expect(wrapper.find('.btn-link').exists()).toBe(true))
    expect(wrapper.get('.btn-link').classes()).toContain('touch-target')
    expect(wrapper.get('.btn-link').attributes('type')).toBe('button')
  })
})
