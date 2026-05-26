<script setup lang="ts">
import type { RunSpanNode } from '~/composables/useAiObservabilityRunDetail'
import { useLanguage } from '~/composables/useLanguage'

const { t } = useLanguage()

interface Props {
  span: RunSpanNode
  depth?: number
}

const props = withDefaults(defineProps<Props>(), { depth: 0 })

const expanded = ref(true)

function toggle(): void {
  expanded.value = !expanded.value
}
</script>

<template>
  <div
    class="span-node"
    :style="{ paddingLeft: `${props.depth * 20}px` }"
    :data-testid="`span-node-${span.spanUuid}`"
  >
    <div class="span-header">
      <button
        v-if="span.children.length > 0 || span.truncated"
        type="button"
        class="expand-btn"
        :aria-expanded="expanded"
        :data-testid="`span-expand-${span.spanUuid}`"
        @click="toggle"
      >
        {{ expanded ? '▾' : '▸' }}
      </button>
      <span v-else class="expand-placeholder" />
      <span class="span-kind" :data-testid="`span-kind-${span.spanUuid}`">{{ span.kind }}</span>
      <span class="span-name" :data-testid="`span-name-${span.spanUuid}`">{{ span.name }}</span>
      <span class="span-status" :class="`status-${span.status}`">{{ span.status }}</span>
    </div>

    <template v-if="expanded">
      <div
        v-if="span.truncated"
        class="span-truncated"
        :data-testid="`span-truncated-${span.spanUuid}`"
      >
        {{ t('ai_runs_span_truncated') }}
      </div>
      <RunSpanNode
        v-for="child in span.children"
        :key="child.spanUuid"
        :span="child"
        :depth="props.depth + 1"
      />
    </template>
  </div>
</template>

<style scoped>
.span-node {
  border-left: 2px solid var(--color-border, #e5e7eb);
  margin-left: 8px;
  margin-bottom: 2px;
}
.span-header {
  display: flex;
  align-items: center;
  gap: 6px;
  padding: 4px 8px;
  font-size: 13px;
}
.expand-btn {
  appearance: none;
  background: none;
  border: none;
  cursor: pointer;
  padding: 0 2px;
  font-size: 12px;
  color: var(--color-muted);
  line-height: 1;
}
.expand-placeholder {
  display: inline-block;
  width: 14px;
}
.span-kind {
  font-size: 11px;
  font-weight: 600;
  text-transform: uppercase;
  color: var(--color-muted);
  letter-spacing: 0.04em;
  min-width: 72px;
}
.span-name {
  flex: 1;
  color: var(--color-text);
  font-family: monospace;
}
.span-status {
  font-size: 11px;
  padding: 1px 6px;
  border-radius: 3px;
}
.status-ok { background: #dcfce7; color: #166534; }
.status-error { background: #fee2e2; color: #991b1b; }
.span-truncated {
  padding: 4px 8px;
  font-size: 12px;
  color: var(--color-muted);
  font-style: italic;
}
</style>
