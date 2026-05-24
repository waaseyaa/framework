<script setup lang="ts">
// WorkflowGuardsTable — read-only matrix of (bundle, transition, required_roles)
// for a single workflow id. M4A-5 Phase 1 (#1470).
//
// Mirrors the layout patterns used elsewhere in /workflows/[id] — `entity-table`
// styling, chip lists, empty state — so the section feels native to the page.

import type { WorkflowGuardRow } from '~/composables/useWorkflowGuards'
import { useLanguage } from '~/composables/useLanguage'

defineProps<{
  rows: WorkflowGuardRow[]
  loading?: boolean
  error?: string | null
}>()

const { t } = useLanguage()
</script>

<template>
  <section class="workflow-section" data-testid="workflow-guards-matrix">
    <h2>{{ t('workflow_guards_title') }}</h2>
    <p class="workflow-guards-help">{{ t('workflow_guards_help') }}</p>

    <div v-if="loading" class="loading">{{ t('loading') }}</div>

    <div v-else-if="error" class="error" data-testid="workflow-guards-error">{{ error }}</div>

    <p v-else-if="rows.length === 0" class="empty-state" data-testid="workflow-guards-empty">
      {{ t('workflow_guards_empty') }}
    </p>

    <table v-else class="entity-table workflow-guards-table" data-testid="workflow-guards-table">
      <thead>
        <tr>
          <th>{{ t('workflow_guards_column_bundle') }}</th>
          <th>{{ t('workflow_guards_column_transition') }}</th>
          <th>{{ t('workflow_guards_column_required_roles') }}</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="(row, idx) in rows" :key="`${row.bundle}::${row.transition}::${idx}`">
          <td><code>{{ row.bundle }}</code></td>
          <td><code>{{ row.transition }}</code></td>
          <td>
            <span v-if="row.required_roles.length === 0" class="workflow-guards-no-roles">—</span>
            <ul v-else class="workflow-guards-role-chips">
              <li v-for="role in row.required_roles" :key="role" class="workflow-guards-role-chip">
                {{ role }}
              </li>
            </ul>
          </td>
        </tr>
      </tbody>
    </table>
  </section>
</template>

<style scoped>
.workflow-guards-help {
  margin: 0 0 12px;
  font-size: 13px;
  color: var(--color-muted);
}

.workflow-guards-role-chips {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-wrap: wrap;
  gap: 4px;
}

.workflow-guards-role-chip {
  font-size: 12px;
  padding: 2px 8px;
  border-radius: 12px;
  background: rgba(15, 118, 110, 0.12);
  color: var(--color-text);
  font-family: var(--font-mono, monospace);
}

.workflow-guards-no-roles {
  color: var(--color-muted);
}
</style>
