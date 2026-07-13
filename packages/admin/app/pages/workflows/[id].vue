<script setup lang="ts">
import { useWorkflowDefinitions, type WorkflowTransition } from '~/composables/useWorkflowDefinitions'
import { useLanguage } from '~/composables/useLanguage'

const route = useRoute()
const { t } = useLanguage()
const { loading, error, fetchWorkflows, findById } = useWorkflowDefinitions()

const workflowId = computed(() => String(route.params.id))
const workflow = computed(() => findById(workflowId.value))

await fetchWorkflows()

const { appName } = useAdminConfig()
useHead({
  title: computed(() => {
    const label = workflow.value?.label ?? workflowId.value
    return `${t('workflow_detail_title').replace('{label}', label)} | ${appName}`
  }),
})

// Sort states by weight for deterministic axis order.
const sortedStates = computed(() => {
  if (!workflow.value) return []
  return [...workflow.value.states].sort((a, b) => a.weight - b.weight)
})

// Build a lookup: `${from}->${to}` -> transitions[] so the matrix cells can
// render zero or more transitions per directed pair.
const transitionsByPair = computed(() => {
  const map = new Map<string, WorkflowTransition[]>()
  if (!workflow.value) return map
  for (const transition of workflow.value.transitions) {
    for (const from of transition.from) {
      const key = `${from}->${transition.to}`
      const existing = map.get(key) ?? []
      existing.push(transition)
      map.set(key, existing)
    }
  }
  return map
})

function transitionsFor(fromId: string, toId: string): WorkflowTransition[] {
  return transitionsByPair.value.get(`${fromId}->${toId}`) ?? []
}

function metadataEntries(metadata: Record<string, unknown>): Array<[string, string]> {
  return Object.entries(metadata).map(([k, v]) => [k, String(v)])
}
</script>

<template>
  <div>
    <div class="page-header">
      <h1>
        {{ workflow ? workflow.label : workflowId }}
        <code v-if="workflow" class="workflow-id-chip">{{ workflow.id }}</code>
      </h1>
      <NuxtLink to="/workflows" class="btn btn-sm">{{ t('back_to_list') }}</NuxtLink>
    </div>

    <div v-if="loading" class="loading">{{ t('loading') }}</div>

    <div v-else-if="error" class="error">{{ error }}</div>

    <p v-else-if="!workflow" class="empty-state">
      {{ t('workflow_detail_not_found') }}
    </p>

    <template v-else>
      <section class="workflow-section" data-testid="workflow-states">
        <h2>{{ t('workflow_states') }} ({{ sortedStates.length }})</h2>
        <table class="entity-table">
          <thead>
            <tr>
              <th>{{ t('workflow_id') }}</th>
              <th>{{ t('workflow_label') }}</th>
              <th>{{ t('workflow_state_weight') }}</th>
              <th>{{ t('workflow_state_metadata') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="state in sortedStates" :key="state.id">
              <td><code>{{ state.id }}</code></td>
              <td>{{ state.label }}</td>
              <td>{{ state.weight }}</td>
              <td>
                <span v-if="metadataEntries(state.metadata).length === 0">—</span>
                <ul v-else class="metadata-list">
                  <li v-for="[k, v] in metadataEntries(state.metadata)" :key="k">
                    <code>{{ k }}</code>: {{ v }}
                  </li>
                </ul>
              </td>
            </tr>
          </tbody>
        </table>
      </section>

      <section class="workflow-section" data-testid="workflow-transitions-matrix">
        <h2>{{ t('workflow_transition_matrix') }} ({{ workflow.transitions.length }})</h2>
        <table class="entity-table workflow-matrix">
          <thead>
            <tr>
              <th>{{ t('workflow_transition_sources') }} \ {{ t('workflow_transition_target') }}</th>
              <th v-for="state in sortedStates" :key="state.id">
                <code>{{ state.id }}</code>
              </th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="fromState in sortedStates" :key="fromState.id">
              <th scope="row"><code>{{ fromState.id }}</code></th>
              <td
                v-for="toState in sortedStates"
                :key="toState.id"
                :class="{ 'matrix-cell-has-transition': transitionsFor(fromState.id, toState.id).length > 0 }"
              >
                <template v-if="transitionsFor(fromState.id, toState.id).length === 0">
                  {{ t('workflow_transition_matrix_empty_cell') }}
                </template>
                <ul v-else class="transition-list">
                  <li v-for="transition in transitionsFor(fromState.id, toState.id)" :key="transition.id">
                    {{ transition.label }}
                    <code>{{ transition.id }}</code>
                  </li>
                </ul>
              </td>
            </tr>
          </tbody>
        </table>
      </section>

    </template>
  </div>
</template>

<style scoped>
.workflow-id-chip {
  font-size: 14px;
  font-weight: 400;
  padding: 2px 8px;
  border-radius: 4px;
  background: var(--color-bg);
  margin-left: 8px;
  vertical-align: middle;
}

.workflow-section {
  margin-bottom: 32px;
}

.workflow-section h2 {
  font-size: 16px;
  font-weight: 600;
  margin-bottom: 12px;
  color: var(--color-muted);
  text-transform: uppercase;
  letter-spacing: 0.03em;
}

.metadata-list,
.transition-list {
  list-style: none;
  margin: 0;
  padding: 0;
}

.metadata-list li,
.transition-list li {
  font-size: 13px;
}

.workflow-matrix th[scope="row"] {
  background: var(--color-bg);
  font-weight: 600;
}

.matrix-cell-has-transition {
  background: rgba(15, 118, 110, 0.06);
}
</style>
