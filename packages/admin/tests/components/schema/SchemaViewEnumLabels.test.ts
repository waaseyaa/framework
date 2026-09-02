import { describe, expect, it, vi } from 'vitest'
import { mountSuspended } from '@nuxt/test-utils/runtime'
import { ref } from 'vue'
import SchemaView from '~/components/schema/SchemaView.vue'

// Multi-cardinality enums arrive as `{ type: 'array', items: { enum } }` with the
// labels on the field itself; single-cardinality enums keep `enum` at the top
// level. Both shapes must resolve the same `x-enum-labels` map, and an array
// without labels must still render as a plain joined list.
vi.mock('~/composables/useSchema', () => ({
  useSchema: () => ({
    error: ref(null),
    fetch: vi.fn().mockResolvedValue(undefined),
    sortedProperties: () => [
      ['tags', {
        type: 'array',
        items: { type: 'string', enum: ['a', 'b', 'c'] },
        'x-enum-labels': { a: 'Alpha', b: 'Beta' },
        'x-label': 'Tags',
      }],
      ['status', {
        type: 'string',
        enum: ['draft', 'published'],
        'x-enum-labels': { draft: 'Draft', published: 'Published' },
        'x-label': 'Status',
      }],
      ['kind', {
        type: 'string',
        items: { enum: ['x', 'y'] },
        'x-enum-labels': { x: 'Ex' },
        'x-label': 'Kind',
      }],
      ['plain', { type: 'array', items: { type: 'string' }, 'x-label': 'Plain' }],
    ],
  }),
}))

vi.mock('~/composables/useEntity', () => ({
  useEntity: () => ({
    get: vi.fn().mockResolvedValue({
      id: '1',
      attributes: { tags: ['a', 'b', 'c'], status: 'published', kind: 'x', plain: ['one', 'two'] },
    }),
  }),
}))

vi.mock('~/composables/useLanguage', () => ({
  useLanguage: () => ({ t: (key: string) => key }),
}))

describe('SchemaView enum label presentation', () => {
  it('maps multi-cardinality and scalar enum values through x-enum-labels', async () => {
    const wrapper = await mountSuspended(SchemaView, {
      props: { entityType: 'node', entityId: '1' },
    })
    await vi.waitFor(() => expect(wrapper.findAll('.field-row').length).toBe(4))

    const valueFor = (name: string) =>
      wrapper.get(`[data-anchor="field:node:${name}"] .field-value`).text()

    // Labelled items are mapped; an unlabelled item falls back to its raw value.
    expect(valueFor('tags')).toBe('Alpha, Beta, c')
    // Top-level enum with labels still resolves.
    expect(valueFor('status')).toBe('Published')
    // Scalar value whose enum sits under items.enum still resolves its label.
    expect(valueFor('kind')).toBe('Ex')
    // Arrays without labels stay a plain joined list.
    expect(valueFor('plain')).toBe('one, two')
  })
})
