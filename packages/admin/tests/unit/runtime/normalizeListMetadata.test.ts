import { describe, expect, it } from 'vitest'
import { normalizeListMetadata } from '~/runtime/normalizeListMetadata'

describe('normalizeListMetadata', () => {
  it('accepts the closed inert contract', () => {
    const metadata = normalizeListMetadata({
      columns: [
        { field: 'kind', label: 'Kind', sortable: false, formatter: 'enum', valueLabels: { news: '<img src=x onerror=alert(1)>' } },
        { field: 'changed', label: 'Changed', sortable: true, formatter: 'datetime' },
      ],
      search: { field: 'title', operator: 'STARTS_WITH', label: 'Search titles', description: 'Beginning of title' },
      filters: [{ field: 'kind', operator: 'EQUALS', label: 'Content type', options: [{ value: 'news', label: '<News>' }] }],
      sorts: [{ field: 'changed', direction: 'DESC', label: 'Recently changed' }],
      defaultSort: { field: 'changed', direction: 'DESC' },
    })

    expect(metadata?.columns[0]?.valueLabels?.news).toBe('<img src=x onerror=alert(1)>')
    expect(metadata?.search?.operator).toBe('STARTS_WITH')
  })

  it.each([
    { columns: [{ field: 'title', label: 'Title', sortable: false, formatter: 'html' }] },
    { columns: [{ field: 'title', label: 'Title', sortable: false, formatter: 'text', callback: 'alert' }] },
    { search: { field: 'title', label: 'Search', operator: 'REGEX' } },
    { filters: [{ field: 'kind', label: 'Kind', operator: 'REGEX' }] },
    { sorts: [{ field: 'title', label: 'Title', direction: 'SIDEWAYS' }] },
  ])('fails malformed or executable metadata closed', (raw) => {
    expect(normalizeListMetadata(raw)).toBeNull()
  })
})
