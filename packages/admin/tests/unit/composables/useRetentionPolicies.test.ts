// packages/admin/tests/unit/composables/useRetentionPolicies.test.ts
// useRetentionPolicies reads + mutates the RetentionPolicy entity through the
// friendly /api/classification/policies JSON:API alias routes
// (classification-retention-engine-01KSEFTH WP04). Mirrors useQueueJobs.test.ts.
import { describe, it, expect, beforeEach } from 'vitest'
import { registerEndpoint } from '@nuxt/test-utils/runtime'
import { useRetentionPolicies } from '~/composables/useRetentionPolicies'

interface StoredAttrs {
  name: string
  applies_to: unknown
  action: string
  trigger_kind: string
  trigger_value: string
  exemptions: unknown
}

let listResource: Array<{ type: string, id: string, attributes: StoredAttrs }> = []
let deletedIds: string[] = []

registerEndpoint('/api/classification/policies', {
  method: 'GET',
  handler: () => ({ data: listResource }),
})

registerEndpoint('/api/classification/policies', {
  method: 'POST',
  handler: () => ({
    data: {
      type: 'retention_policy',
      id: '99',
      attributes: {
        name: 'Created',
        applies_to: ['internal'],
        action: 'purge',
        trigger_kind: 'age_based',
        trigger_value: 'P30D',
        exemptions: [],
      },
    },
  }),
})

registerEndpoint('/api/classification/policies/p1', {
  method: 'DELETE',
  handler: () => {
    deletedIds.push('p1')
    return null
  },
})

describe('useRetentionPolicies', () => {
  beforeEach(() => {
    listResource = []
    deletedIds = []
  })

  it('fetches policies and hydrates JSON:API resources', async () => {
    listResource = [
      {
        type: 'retention_policy',
        id: 'p1',
        attributes: {
          name: 'Purge old internal',
          applies_to: ['internal', 'confidential'],
          action: 'purge',
          trigger_kind: 'age_based',
          trigger_value: 'P90D',
          exemptions: ['node:abc'],
        },
      },
    ]

    const { policies, fetchPolicies, error } = useRetentionPolicies()
    await fetchPolicies()

    expect(error.value).toBeNull()
    expect(policies.value).toHaveLength(1)
    expect(policies.value[0]?.id).toBe('p1')
    expect(policies.value[0]?.name).toBe('Purge old internal')
    expect(policies.value[0]?.applies_to).toEqual(['internal', 'confidential'])
    expect(policies.value[0]?.action).toBe('purge')
  })

  it('decodes JSON-string array columns defensively', async () => {
    listResource = [
      {
        type: 'retention_policy',
        id: 'p1',
        attributes: {
          name: 'Glob',
          applies_to: '["nation-confidential","nation-sacred"]',
          action: 'hold-flag',
          trigger_kind: 'event_based',
          trigger_value: '',
          exemptions: '[]',
        },
      },
    ]

    const { policies, fetchPolicies } = useRetentionPolicies()
    await fetchPolicies()

    expect(policies.value[0]?.applies_to).toEqual(['nation-confidential', 'nation-sacred'])
    expect(policies.value[0]?.exemptions).toEqual([])
  })

  it('creates a new policy via POST', async () => {
    const { savePolicy } = useRetentionPolicies()
    const saved = await savePolicy({
      id: 'new',
      name: 'Created',
      applies_to: ['internal'],
      action: 'purge',
      trigger_kind: 'age_based',
      trigger_value: 'P30D',
      exemptions: [],
    })

    expect(saved).not.toBeNull()
    expect(saved?.id).toBe('99')
    expect(saved?.name).toBe('Created')
  })

  it('deletes a policy and drops it from local state', async () => {
    const composable = useRetentionPolicies()
    composable.policies.value = [
      {
        id: 'p1',
        name: 'Doomed',
        applies_to: ['internal'],
        action: 'purge',
        trigger_kind: 'age_based',
        trigger_value: 'P1D',
        exemptions: [],
      },
    ]

    const ok = await composable.deletePolicy('p1')

    expect(ok).toBe(true)
    expect(deletedIds).toContain('p1')
    expect(composable.policies.value).toHaveLength(0)
  })
})
