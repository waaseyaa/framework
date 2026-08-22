// packages/admin/tests/unit/composables/useWorkflowTransitions.test.ts
// useWorkflowTransitions fetches GET /api/{entityType}/{id}/workflow/transitions
// and POSTs /api/{entityType}/{id}/workflow/transition. Mirrors
// useWorkflowDefinitions.test.ts (endpoint path prefix conventions, apiFetch usage).
import { describe, it, expect, beforeEach, vi } from 'vitest'
import { registerEndpoint } from '@nuxt/test-utils/runtime'
import { getRequestHeaders } from 'h3'

const publishTransition = { id: 'publish', label: 'Publish', to: 'published' }
const archiveTransition = { id: 'archive', label: 'Archive', to: 'archived' }
// The controller derives this from the working copy the transition targets and
// returns it as meta.mutation_token plus a strong ETag. It is the only
// authoritative validator for the subsequent POST.
const discoveryToken = 'emt1.eyJzIjoibG9jYWwiLCJ2IjozfQ'
const successorToken = 'emt1.eyJzIjoibG9jYWwiLCJ2Ijo0fQ'

/** Headers observed by each endpoint, so leakage is provable and not assumed. */
const observed = new Map<string, { ifMatch: string | undefined }>()

function record(label: string, event: unknown): void {
  const headers = getRequestHeaders(event as Parameters<typeof getRequestHeaders>[0])
  // HTTP header names are case-insensitive and the test transport preserves the
  // casing the client used, so normalise before looking one up.
  const normalised: Record<string, string | undefined> = {}
  for (const [name, value] of Object.entries(headers)) normalised[name.toLowerCase()] = value
  observed.set(label, { ifMatch: normalised['if-match'] })
}

const publishHistory = {
  transition: 'submit_for_review',
  from: 'draft',
  to: 'review',
  uid: '9',
  at: '2026-08-13 14:00:00',
}

registerEndpoint('/api/node/5/workflow/transitions', (event: unknown) => {
  record('discovery:5', event)
  return {
    data: [publishTransition, archiveTransition],
    meta: {
      workflow_state: 'review',
      workflow_history: [publishHistory],
      mutation_token: discoveryToken,
    },
  }
})

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
  handler: (event: unknown) => {
    record('apply:5', event)
    return {
      data: { transition: 'publish', from: 'review', to: 'published', public_changed: true },
      meta: { mutation_token: successorToken },
    }
  },
})

registerEndpoint('/api/node/private/workflow/transitions', () => ({
  data: [publishTransition],
  meta: { workflow_state: 'draft', mutation_token: discoveryToken },
}))

registerEndpoint('/api/node/legacy/workflow/transitions', () => ({
  data: [publishTransition],
  meta: { workflow_state: 'review', mutation_token: discoveryToken },
}))

registerEndpoint('/api/node/bad-flag/workflow/transitions', () => ({
  data: [publishTransition],
  meta: { workflow_state: 'review', mutation_token: discoveryToken },
}))

registerEndpoint('/api/node/7/workflow/transitions', () => ({
  data: [publishTransition],
  meta: { workflow_state: 'review', mutation_token: discoveryToken },
}))

registerEndpoint('/api/node/stale/workflow/transitions', () => ({
  data: [publishTransition],
  meta: { workflow_state: 'review', mutation_token: discoveryToken },
}))

registerEndpoint('/api/node/stale/workflow/transition', {
  method: 'POST',
  handler: (event: unknown) => {
    const e = event as { node?: { res?: { statusCode: number } } }
    if (e.node?.res) e.node.res.statusCode = 412
    throw createError({
      status: 412,
      data: {
        errors: [{
          status: '412',
          code: 'MUTATION_PRECONDITION_FAILED',
          detail: 'The resource changed after the supplied mutation precondition was observed.',
        }],
      },
    })
  },
})

registerEndpoint('/api/node/refused/workflow/transitions', () => ({
  data: [publishTransition],
  meta: { workflow_state: 'review', mutation_token: discoveryToken },
}))

registerEndpoint('/api/node/refused/workflow/transition', {
  method: 'POST',
  handler: (event: unknown) => {
    const e = event as { node?: { res?: { statusCode: number } } }
    if (e.node?.res) e.node.res.statusCode = 428
    throw createError({
      status: 428,
      data: {
        errors: [{
          status: '428',
          code: 'MUTATION_PRECONDITION_REQUIRED',
          detail: 'If-Match is required for an existing aggregate mutation.',
        }],
      },
    })
  },
})

registerEndpoint('/api/node/no-token/workflow/transitions', () => ({
  data: [publishTransition],
  meta: { workflow_state: 'review', mutation_token: discoveryToken },
}))

registerEndpoint('/api/node/no-token/workflow/transition', {
  method: 'POST',
  handler: () => ({ data: { transition: 'publish', from: 'review', to: 'published', public_changed: true } }),
})

registerEndpoint('/api/node/tokenless/workflow/transitions', () => ({
  data: [publishTransition],
  meta: { workflow_state: 'review' },
}))

registerEndpoint('/api/node/malformed-apply/workflow/transitions', () => ({
  data: [publishTransition],
  meta: { workflow_state: 'review', mutation_token: discoveryToken },
}))

registerEndpoint('/api/node/private/workflow/transition', {
  method: 'POST',
  handler: () => ({ data: { transition: 'submit_for_review', from: 'draft', to: 'review', public_changed: false } }),
})

registerEndpoint('/api/node/legacy/workflow/transition', {
  method: 'POST',
  handler: () => ({ data: { transition: 'publish', from: 'review', to: 'published' } }),
})

registerEndpoint('/api/node/malformed-apply/workflow/transition', {
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
  observed.clear()
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

  // The transition POST is an aggregate mutation. WorkflowTransitionController
  // requires a strong If-Match entity mutation ETag and answers 428 without
  // one, so before this suite the shipped SPA could never apply a transition.
  it('sends the discovery mutation token as a strong If-Match ETag on the transition', async () => {
    const { useWorkflowTransitions } = await import('~/composables/useWorkflowTransitions')
    const { fetchTransitions, applyTransition } = useWorkflowTransitions()
    await fetchTransitions('node', '5')
    await applyTransition('node', '5', 'publish')
    expect(observed.get('apply:5')?.ifMatch).toBe(`"${discoveryToken}"`)
  })

  it('takes the validator from the discovery response and never from the entity id or a guess', async () => {
    const { useWorkflowTransitions } = await import('~/composables/useWorkflowTransitions')
    const { fetchTransitions, mutationToken } = useWorkflowTransitions()
    expect(mutationToken.value).toBeNull()
    await fetchTransitions('node', '5')
    expect(mutationToken.value).toBe(discoveryToken)
  })

  it('never sends If-Match on the discovery read', async () => {
    const { useWorkflowTransitions } = await import('~/composables/useWorkflowTransitions')
    const { fetchTransitions, applyTransition } = useWorkflowTransitions()
    await fetchTransitions('node', '5')
    await applyTransition('node', '5', 'publish')
    expect(observed.get('discovery:5')?.ifMatch).toBeUndefined()
  })

  it('adopts the successor the server issued so a second transition is fenced by committed state', async () => {
    const { useWorkflowTransitions } = await import('~/composables/useWorkflowTransitions')
    const { fetchTransitions, applyTransition, mutationToken } = useWorkflowTransitions()
    await fetchTransitions('node', '5')
    await applyTransition('node', '5', 'publish')
    expect(mutationToken.value).toBe(successorToken)
  })

  it('holds no validator when the apply response carries no successor', async () => {
    const { useWorkflowTransitions } = await import('~/composables/useWorkflowTransitions')
    const { fetchTransitions, applyTransition, mutationToken } = useWorkflowTransitions()
    await fetchTransitions('node', 'no-token')
    await applyTransition('node', 'no-token', 'publish')
    expect(mutationToken.value).toBeNull()
  })

  it('refuses to apply without a validator instead of posting an unfenced mutation', async () => {
    const { useWorkflowTransitions, WorkflowTransitionPreconditionError }
      = await import('~/composables/useWorkflowTransitions')
    const { applyTransition } = useWorkflowTransitions()
    await expect(applyTransition('node', '5', 'publish')).rejects.toBeInstanceOf(WorkflowTransitionPreconditionError)
    expect(observed.has('apply:5')).toBe(false)
  })

  it('refuses to apply a validator observed for a different entity', async () => {
    const { useWorkflowTransitions, WorkflowTransitionPreconditionError }
      = await import('~/composables/useWorkflowTransitions')
    const { fetchTransitions, applyTransition } = useWorkflowTransitions()
    await fetchTransitions('node', 'legacy')
    await expect(applyTransition('node', '5', 'publish')).rejects.toBeInstanceOf(WorkflowTransitionPreconditionError)
    expect(observed.has('apply:5')).toBe(false)
  })

  it('drops a validator the server refused as stale and does not retry unfenced', async () => {
    const { useWorkflowTransitions } = await import('~/composables/useWorkflowTransitions')
    const { fetchTransitions, applyTransition, mutationToken } = useWorkflowTransitions()
    await fetchTransitions('node', 'stale')
    await expect(applyTransition('node', 'stale', 'publish')).rejects.toBeTruthy()
    expect(mutationToken.value).toBeNull()
  })

  it('drops the validator when the server reports the precondition missing', async () => {
    const { useWorkflowTransitions } = await import('~/composables/useWorkflowTransitions')
    const { fetchTransitions, applyTransition, mutationToken } = useWorkflowTransitions()
    await fetchTransitions('node', 'refused')
    await expect(applyTransition('node', 'refused', 'publish')).rejects.toBeTruthy()
    expect(mutationToken.value).toBeNull()
  })

  it('requires a freshly observed validator after a refusal rather than reusing the stale one', async () => {
    const { useWorkflowTransitions, WorkflowTransitionPreconditionError }
      = await import('~/composables/useWorkflowTransitions')
    const { fetchTransitions, applyTransition } = useWorkflowTransitions()
    await fetchTransitions('node', 'stale')
    await expect(applyTransition('node', 'stale', 'publish')).rejects.toBeTruthy()
    await expect(applyTransition('node', 'stale', 'publish')).rejects.toBeInstanceOf(WorkflowTransitionPreconditionError)
  })

  it('forgets the validator when a later discovery fails', async () => {
    const { useWorkflowTransitions } = await import('~/composables/useWorkflowTransitions')
    const { fetchTransitions, mutationToken } = useWorkflowTransitions()
    await fetchTransitions('node', '5')
    expect(mutationToken.value).toBe(discoveryToken)
    await fetchTransitions('node', 'forbidden')
    expect(mutationToken.value).toBeNull()
  })

  it('holds no validator when discovery offers transitions but issues no token', async () => {
    const { useWorkflowTransitions, WorkflowTransitionPreconditionError }
      = await import('~/composables/useWorkflowTransitions')
    const { fetchTransitions, applyTransition, mutationToken } = useWorkflowTransitions()
    await fetchTransitions('node', 'tokenless')
    expect(mutationToken.value).toBeNull()
    await expect(applyTransition('node', 'tokenless', 'publish')).rejects.toBeInstanceOf(WorkflowTransitionPreconditionError)
  })

  it('holds no validator when discovery offers no transitions', async () => {
    const { useWorkflowTransitions } = await import('~/composables/useWorkflowTransitions')
    const { fetchTransitions, mutationToken } = useWorkflowTransitions()
    await fetchTransitions('node', '6')
    expect(mutationToken.value).toBeNull()
  })

  it('classifies a refused precondition as its own error kind', async () => {
    const { classifyWorkflowTransitionError } = await import('~/composables/useWorkflowTransitions')
    expect(classifyWorkflowTransitionError(new Error('stale'), 412)).toBe('precondition')
    expect(classifyWorkflowTransitionError(new Error('missing'), 428)).toBe('precondition')
  })

  it('applyTransition posts to the transition endpoint and returns an explicit public change', async () => {
    const { useWorkflowTransitions } = await import('~/composables/useWorkflowTransitions')
    const { fetchTransitions, applyTransition } = useWorkflowTransitions()
    await fetchTransitions('node', '5')
    const result = await applyTransition('node', '5', 'publish')
    expect(result).toEqual({ transition: 'publish', from: 'review', to: 'published', public_changed: true })
  })

  it('applyTransition preserves an explicit false public-change flag', async () => {
    const { useWorkflowTransitions } = await import('~/composables/useWorkflowTransitions')
    const { fetchTransitions, applyTransition } = useWorkflowTransitions()
    await fetchTransitions('node', 'private')
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
    const { fetchTransitions, applyTransition } = useWorkflowTransitions()
    await fetchTransitions('node', 'legacy')
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
    const { fetchTransitions, applyTransition } = useWorkflowTransitions()
    await fetchTransitions('node', 'malformed-apply')
    await expect(applyTransition('node', 'malformed-apply', 'publish')).rejects.toThrow()
  })

  it('applyTransition still rejects a present non-boolean public_changed value', async () => {
    const { useWorkflowTransitions } = await import('~/composables/useWorkflowTransitions')
    const { fetchTransitions, applyTransition } = useWorkflowTransitions()
    await fetchTransitions('node', 'bad-flag')
    await expect(applyTransition('node', 'bad-flag', 'publish')).rejects.toThrow()
  })

  it('applyTransition rejects with the raw error on a 403 permission denial', async () => {
    const { useWorkflowTransitions } = await import('~/composables/useWorkflowTransitions')
    const { fetchTransitions, applyTransition } = useWorkflowTransitions()
    await fetchTransitions('node', '7')
    await expect(applyTransition('node', '7', 'publish')).rejects.toBeTruthy()
  })
})
