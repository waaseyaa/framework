// packages/admin/tests/unit/composables/useWorkflowDefinitions.test.ts
// useWorkflowDefinitions fetches GET /api/workflow-definitions and exposes
// the array of WorkflowDefinition values under workflows.value.
import { describe, it, expect, beforeEach, vi } from 'vitest'
import { registerEndpoint } from '@nuxt/test-utils/runtime'

const editorial = {
  id: 'editorial',
  label: 'Editorial',
  states: [
    { id: 'draft', label: 'Draft', weight: 0, metadata: { legacy_status: 0 } },
    { id: 'review', label: 'Review', weight: 1, metadata: {} },
    { id: 'published', label: 'Published', weight: 2, metadata: { legacy_status: 1 } },
    { id: 'archived', label: 'Archived', weight: 3, metadata: {} },
  ],
  transitions: [
    { id: 'submit_for_review', label: 'Submit for Review', from: ['draft'], to: 'review', weight: 0 },
  ],
}

registerEndpoint('/admin/api/workflow-definitions', () => ({ data: [editorial] }))

beforeEach(() => {
  vi.resetModules()
})

describe('useWorkflowDefinitions', () => {
  it('starts empty with no error and not loading', async () => {
    const { useWorkflowDefinitions } = await import('~/composables/useWorkflowDefinitions')
    const { workflows, loading, error } = useWorkflowDefinitions()
    expect(workflows.value).toEqual([])
    expect(loading.value).toBe(false)
    expect(error.value).toBeNull()
  })

  it('fetches and populates workflows from /api/workflow-definitions', async () => {
    const { useWorkflowDefinitions } = await import('~/composables/useWorkflowDefinitions')
    const { workflows, loading, error, fetchWorkflows } = useWorkflowDefinitions()
    await fetchWorkflows()
    expect(loading.value).toBe(false)
    expect(error.value).toBeNull()
    expect(workflows.value).toHaveLength(1)
    expect(workflows.value[0].id).toBe('editorial')
    expect(workflows.value[0].states).toHaveLength(4)
    expect(workflows.value[0].transitions).toHaveLength(1)
  })

  it('findById returns the matching workflow after fetch', async () => {
    const { useWorkflowDefinitions } = await import('~/composables/useWorkflowDefinitions')
    const { fetchWorkflows, findById } = useWorkflowDefinitions()
    await fetchWorkflows()
    expect(findById('editorial')?.id).toBe('editorial')
  })

  it('findById returns null for an unknown id', async () => {
    const { useWorkflowDefinitions } = await import('~/composables/useWorkflowDefinitions')
    const { fetchWorkflows, findById } = useWorkflowDefinitions()
    await fetchWorkflows()
    expect(findById('nonexistent')).toBeNull()
  })

  it('findById returns null before fetch (workflows are empty)', async () => {
    const { useWorkflowDefinitions } = await import('~/composables/useWorkflowDefinitions')
    const { findById } = useWorkflowDefinitions()
    expect(findById('editorial')).toBeNull()
  })
})
