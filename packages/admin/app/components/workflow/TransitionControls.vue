<script setup lang="ts">
// workflow/TransitionControls.vue — nested-dir component, referenced
// elsewhere as <WorkflowTransitionControls> (Nuxt auto-import prefix
// convention; same shape as workflow/TransitionHistoryTimeline.vue ->
// <WorkflowTransitionHistoryTimeline>). Renders one button per available
// CW-v1 transition (WP-4 Task C, #1920) and applies the chosen transition.
import { useWorkflowTransitions, type WorkflowTransitionApplyResult } from '~/composables/useWorkflowTransitions'
import { useLanguage } from '~/composables/useLanguage'
import { requireAdminRuntime } from '~/composables/useAdminRuntime'
import { isMutationTokenAware } from '~/contracts/transport'

const props = defineProps<{
  entityType: string
  entityId: string
}>()

const emit = defineEmits<{
  transitioned: [result: WorkflowTransitionApplyResult]
}>()

const { t } = useLanguage()
const { transitions, state, loading, error: fetchError, mutationToken, fetchTransitions, applyTransition } = useWorkflowTransitions()

const loaded = ref(false)
const pending = ref(false)
const applyError = ref<string | null>(null)

async function load() {
  await fetchTransitions(props.entityType, props.entityId)
  loaded.value = true
}

onMounted(load)

async function apply(transitionId: string) {
  if (pending.value) return

  pending.value = true
  applyError.value = null
  try {
    const result = await applyTransition(props.entityType, props.entityId, transitionId)
    // A committed transition writes a new revision, which supersedes the
    // validator the admin-surface transport cached on its last read. Hand the
    // successor over so a following save or delete is fenced by committed state
    // instead of being refused with 412; when no successor was issued, drop the
    // cached one so that write asks for a reload rather than presenting a stale
    // validator. Failing to reach the runtime must not undo the transition.
    try {
      const { transport } = requireAdminRuntime()
      if (isMutationTokenAware(transport)) {
        if (mutationToken.value === null) {
          transport.forgetMutationToken(props.entityType, props.entityId)
        } else {
          transport.adoptMutationToken(props.entityType, props.entityId, mutationToken.value)
        }
      }
    } catch {
      // No admin runtime in this mount; nothing caches a validator to refresh.
    }
    emit('transitioned', result)
    await load()
  } catch (e: unknown) {
    const err = e as { data?: { errors?: Array<{ detail?: string }> }; message?: string }
    applyError.value = err?.data?.errors?.[0]?.detail ?? err?.message ?? t('workflow_transition_error_generic')
    // A refused or missing mutation precondition leaves the composable holding
    // no validator. Re-read the transitions so the operator can act on the
    // current state; never re-post the mutation on their behalf.
    if (mutationToken.value === null) {
      await load()
    }
  } finally {
    pending.value = false
  }
}
</script>

<template>
  <div
    v-if="!loaded || loading"
    class="transition-status"
    role="status"
    aria-live="polite"
  >
    {{ t('workflow_transitions_loading') }}
  </div>

  <div v-else-if="transitions.length > 0 || fetchError" class="transition-controls" data-testid="transition-controls">
    <button
      v-for="transition in transitions"
      :key="transition.id"
      type="button"
      class="btn btn-sm"
      :disabled="pending"
      :aria-label="`${transition.label}: ${transition.to}`"
      :data-testid="`transition-btn-${transition.id}`"
      @click="apply(transition.id)"
    >
      {{ transition.label }}
      <span class="transition-target">&rarr; {{ transition.to }}</span>
    </button>

    <div v-if="applyError" class="error" role="alert" aria-live="assertive" data-testid="transition-error">{{ applyError }}</div>
    <div v-if="fetchError" class="error" role="alert" aria-live="assertive" data-testid="transition-fetch-error">{{ fetchError }}</div>
  </div>

  <div
    v-else-if="state !== null"
    class="transition-status"
    role="status"
    aria-live="polite"
    data-testid="transition-empty"
  >
    {{ t('workflow_transitions_empty') }}
  </div>
</template>

<style scoped>
.transition-controls {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
}

.transition-target {
  font-size: 11px;
  opacity: 0.75;
  margin-left: 4px;
}
</style>
