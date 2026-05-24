// packages/admin/tests/unit/composables/useWorkflowGuards.test.ts
// useWorkflowGuards fetches GET /api/workflow-definitions/{workflow_id}/guards
// and exposes the (bundle, transition, required_roles) rows. Mirrors
// useWorkflowDefinitions.test.ts (M4A-1) for shape.
import { describe, it, expect, beforeEach, vi } from 'vitest'
import { registerEndpoint } from '@nuxt/test-utils/runtime'

const editorialRows = [
  { bundle: 'article', transition: 'archive', required_roles: ['administrator'] },
  { bundle: 'article', transition: 'publish', required_roles: ['editor', 'administrator'] },
]

let nextEditorialResponse: { status: number, body?: unknown } = {
  status: 200,
  body: { data: editorialRows },
}

let nextUnknownResponse: { status: number, body?: unknown } = {
  status: 404,
  body: {
    jsonapi: { version: '1.1' },
    errors: [{ status: '404', title: 'Not Found', detail: 'Workflow "unknown" not found.' }],
  },
}

registerEndpoint('/admin/api/workflow-definitions/editorial/guards', (event: unknown) => {
  if (nextEditorialResponse.status !== 200) {
    const e = event as { node?: { res?: { statusCode: number } } }
    if (e.node?.res) {
      e.node.res.statusCode = nextEditorialResponse.status
    }
  }
  return nextEditorialResponse.body ?? { data: [] }
})

registerEndpoint('/admin/api/workflow-definitions/unknown/guards', (event: unknown) => {
  if (nextUnknownResponse.status !== 200) {
    const e = event as { node?: { res?: { statusCode: number } } }
    if (e.node?.res) {
      e.node.res.statusCode = nextUnknownResponse.status
    }
  }
  return nextUnknownResponse.body ?? { errors: [{ detail: 'not found' }] }
})

beforeEach(() => {
  vi.resetModules()
  nextEditorialResponse = { status: 200, body: { data: editorialRows } }
  nextUnknownResponse = {
    status: 404,
    body: {
      jsonapi: { version: '1.1' },
      errors: [{ status: '404', title: 'Not Found', detail: 'Workflow "unknown" not found.' }],
    },
  }
})

describe('useWorkflowGuards', () => {
  it('starts empty with no error and not loading', async () => {
    const { useWorkflowGuards } = await import('~/composables/useWorkflowGuards')
    const { guards, loading, error } = useWorkflowGuards()
    expect(guards.value).toEqual([])
    expect(loading.value).toBe(false)
    expect(error.value).toBeNull()
  })

  it('fetches and populates rows from /api/workflow-definitions/{id}/guards', async () => {
    const { useWorkflowGuards } = await import('~/composables/useWorkflowGuards')
    const { guards, loading, error, fetchGuards } = useWorkflowGuards()
    await fetchGuards('editorial')
    expect(loading.value).toBe(false)
    expect(error.value).toBeNull()
    expect(guards.value).toHaveLength(2)
    expect(guards.value[0]).toEqual({
      bundle: 'article',
      transition: 'archive',
      required_roles: ['administrator'],
    })
    expect(guards.value[1].required_roles).toEqual(['editor', 'administrator'])
  })

  it('surfaces the API detail and clears rows on a 404', async () => {
    const { useWorkflowGuards } = await import('~/composables/useWorkflowGuards')
    const { guards, error, fetchGuards } = useWorkflowGuards()
    await fetchGuards('unknown')
    expect(error.value).toContain('Workflow "unknown" not found.')
    expect(guards.value).toEqual([])
  })

  it('clears previously-loaded rows when a subsequent fetch fails', async () => {
    const { useWorkflowGuards } = await import('~/composables/useWorkflowGuards')
    const { guards, error, fetchGuards } = useWorkflowGuards()

    await fetchGuards('editorial')
    expect(guards.value).toHaveLength(2)

    await fetchGuards('unknown')
    expect(error.value).toContain('not found')
    expect(guards.value).toEqual([])
  })

  it('encodes the workflow id so slashes do not break the request', async () => {
    // Defensive: the composable must percent-encode the workflow id segment.
    // We register an endpoint at the encoded path; the composable must hit it.
    registerEndpoint('/admin/api/workflow-definitions/has%2Fslash/guards', () => ({
      data: [{ bundle: 'article', transition: 'noop', required_roles: [] }],
    }))

    const { useWorkflowGuards } = await import('~/composables/useWorkflowGuards')
    const { guards, fetchGuards } = useWorkflowGuards()
    await fetchGuards('has/slash')
    expect(guards.value).toHaveLength(1)
    expect(guards.value[0].transition).toBe('noop')
  })
})
