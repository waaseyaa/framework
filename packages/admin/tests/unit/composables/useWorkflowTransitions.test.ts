// packages/admin/tests/unit/composables/useWorkflowTransitions.test.ts
// useWorkflowTransitions fetches GET /api/{entityType}/{id}/workflow/transitions
// and POSTs /api/{entityType}/{id}/workflow/transition. Mirrors
// useWorkflowDefinitions.test.ts (endpoint path prefix conventions, apiFetch usage).
import { describe, it, expect, beforeEach, vi } from 'vitest'
import { registerEndpoint } from '@nuxt/test-utils/runtime'

const publishTransition = { id: 'publish', label: 'Publish', to: 'published' }
const archiveTransition = { id: 'archive', label: 'Archive', to: 'archived' }
const publishHistory = {
  transition: 'submit_for_review',
  from: 'draft',
  to: 'review',
  uid: '9',
  at: '2026-08-13 14:00:00',
}

registerEndpoint('/api/node/5/workflow/transitions', () => ({
  data: [publishTransition, archiveTransition],
  meta: { workflow_state: 'review', workflow_history: [publishHistory] },
}))

registerEndpoint('/api/node/6/workflow/transitions', () => ({
  data: [],
  meta: {},
}))

registerEndpoint('/api/node/malformed/workflow/transitions', () => '<!doctype html><title>Admin</title>')

registerEndpoint('/api/node/forbidden/workflow/transitions', (event: unknown) => {
  const e = event as { node?: { res?: { statusCode: number } } }
  if (e.node?.res) e.node.res.statusCode = 403
  throw createError({
    status: 403,
    data: { errors: [{ detail: 'Workflow discovery is forbidden.' }] },
  })
})

registerEndpoint('/api/node/missing/workflow/transitions', (event: unknown) => {
  const e = event as { node?: { res?: { statusCode: number } } }
  if (e.node?.res) e.node.res.statusCode = 404
  throw createError({ status: 404, message: 'not found' })
})

registerEndpoint('/api/node/5/workflow/transition', {
  method: 'POST',
  handler: () => ({ data: { transition: 'publish', from: 'review', to: 'published', public_changed: true } }),
})

registerEndpoint('/api/node/private/workflow/transition', {
  method: 'POST',
  handler: () => ({ data: { transition: 'submit_for_review', from: 'draft', to: 'review', public_changed: false } }),
})

registerEndpoint('/api/node/legacy/workflow/transition', {
  method: 'POST',
  handler: () => ({ data: { transition: 'publish', from: 'review', to: 'published' } }),
})

registerEndpoint('/api/node/malformed/workflow/transition', {
  method: 'POST',
  handler: () => ({ data: { transition: 'publish', from: 'review' } }),
})

registerEndpoint('/api/node/bad-flag/workflow/transition', {
  method: 'POST',
  handler: () => ({ data: { transition: 'publish', from: 'review', to: 'published', public_changed: 'yes' } }),
})

registerEndpoint('/api/node/7/workflow/transition', {
  method: 'POST',
  handler: (event: unknown) => {
    const e = event as { node?: { res?: { statusCode: number } } }
    if (e.node?.res) e.node.res.statusCode = 403
    throw createError({
      status: 403,
      data: {
        errors: [{ detail: 'You do not have permission to publish this content.', meta: { reason: 'permission' } }],
      },
    })
  },
})

beforeEach(() => {
  vi.resetModules()
})

describe('useWorkflowTransitions', () => {
  it('starts empty with no error and not loading', async () => {
    const { useWorkflowTransitions } = await import('~/composables/useWorkflowTransitions')
    const { transitions, state, history, loading, error } = useWorkflowTransitions()
    expect(transitions.value).toEqual([])
    expect(state.value).toBeNull()
    expect(history.value).toEqual([])
    expect(loading.value).toBe(false)
    expect(error.value).toBeNull()
  })

  it('fetches and populates transitions + workflow_state from the transitions endpoint', async () => {
    const { useWorkflowTransitions } = await import('~/composables/useWorkflowTransitions')
    const { fetchTransitions, loading, error } = useWorkflowTransitions()
    const result = await fetchTransitions('node', '5')
    expect(loading.value).toBe(false)
    expect(error.value).toBeNull()
    expect(result.transitions).toHaveLength(2)
    expect(result.transitions[0].id).toBe('publish')
    expect(result.transitions[0].to).toBe('published')
    expect(result.state).toBe('review')
    expect(result.history).toEqual([publishHistory])
  })

  it('returns an empty transitions list when the endpoint has no available transitions', async () => {
    const { useWorkflowTransitions } = await import('~/composables/useWorkflowTransitions')
    const { fetchTransitions } = useWorkflowTransitions()
    const result = await fetchTransitions('node', '6')
    expect(result.transitions).toEqual([])
  })

  it('treats a 404 as an empty transitions list, not an error', async () => {
    const { useWorkflowTransitions } = await import('~/composables/useWorkflowTransitions')
    const { fetchTransitions, error } = useWorkflowTransitions()
    const result = await fetchTransitions('node', 'missing')
    expect(result.transitions).toEqual([])
    expect(error.value).toBeNull()
  })

  it('reports a structured 403 discovery failure explicitly', async () => {
    const { useWorkflowTransitions } = await import('~/composables/useWorkflowTransitions')
    const { fetchTransitions, error, errorKind } = useWorkflowTransitions()
    await fetchTransitions('node', 'forbidden')
    expect(error.value).toBe('Workflow discovery is forbidden.')
    expect(errorKind.value).toBe('forbidden')
  })

  it('rejects an HTML or malformed discovery document instead of treating it as no transitions', async () => {
    const { useWorkflowTransitions } = await import('~/composables/useWorkflowTransitions')
    const { fetchTransitions, error, errorKind } = useWorkflowTransitions()
    await fetchTransitions('node', 'malformed')
    expect(error.value).toBeTruthy()
    expect(errorKind.value).toBe('malformed_response')
  })

  it('reports network failure separately from an empty transition list', async () => {
    const { classifyWorkflowTransitionError } = await import('~/composables/useWorkflowTransitions')
    expect(classifyWorkflowTransitionError(new TypeError('Network connection failed'), 0)).toBe('network')
  })

  it('applyTransition posts to the transition endpoint and returns an explicit public change', async () => {
    const { useWorkflowTransitions } = await import('~/composables/useWorkflowTransitions')
    const { applyTransition } = useWorkflowTransitions()
    const result = await applyTransition('node', '5', 'publish')
    expect(result).toEqual({ transition: 'publish', from: 'review', to: 'published', public_changed: true })
  })

  it('applyTransition preserves an explicit false public-change flag', async () => {
    const { useWorkflowTransitions } = await import('~/composables/useWorkflowTransitions')
    const { applyTransition } = useWorkflowTransitions()
    const result = await applyTransition('node', 'private', 'submit_for_review')
    expect(result).toEqual({
      transition: 'submit_for_review',
      from: 'draft',
      to: 'review',
      public_changed: false,
    })
  })

  it('treats a committed transition that omits public_changed as success, not operator-visible failure', async () => {
    const { useWorkflowTransitions } = await import('~/composables/useWorkflowTransitions')
    const { applyTransition } = useWorkflowTransitions()
    const result = await applyTransition('node', 'legacy', 'publish')
    expect(result).toEqual({
      transition: 'publish',
      from: 'review',
      to: 'published',
      public_changed: true,
    })
  })

  it('applyTransition still rejects a success document that omits a required member', async () => {
    const { useWorkflowTransitions } = await import('~/composables/useWorkflowTransitions')
    const { applyTransition } = useWorkflowTransitions()
    await expect(applyTransition('node', 'malformed', 'publish')).rejects.toThrow()
  })

  it('applyTransition still rejects a present non-boolean public_changed value', async () => {
    const { useWorkflowTransitions } = await import('~/composables/useWorkflowTransitions')
    const { applyTransition } = useWorkflowTransitions()
    await expect(applyTransition('node', 'bad-flag', 'publish')).rejects.toThrow()
  })

  it('applyTransition rejects with the raw error on a 403 permission denial', async () => {
    const { useWorkflowTransitions } = await import('~/composables/useWorkflowTransitions')
    const { applyTransition } = useWorkflowTransitions()
    await expect(applyTransition('node', '7', 'publish')).rejects.toBeTruthy()
  })
})
