<script setup lang="ts">
import type { CodifiedContextEvent } from '~/composables/useCodifiedContext'

const props = defineProps<{ events: CodifiedContextEvent[] }>()

interface HeatCell {
  path: string
  count: number
  intensity: number
}

const cells = computed<HeatCell[]>(() => {
  const freq: Record<string, number> = {}
  for (const event of props.events) {
    if (event.eventType !== 'context.load') continue
    const paths = event.data?.files
    if (Array.isArray(paths)) {
      for (const p of paths) {
        if (typeof p === 'string') {
          freq[p] = (freq[p] ?? 0) + 1
        }
      }
    }
    // Also check single path
    const singlePath = event.data?.path
    if (typeof singlePath === 'string') {
      freq[singlePath] = (freq[singlePath] ?? 0) + 1
    }
  }

  const max = Math.max(1, ...Object.values(freq))
  return Object.entries(freq)
    .sort(([, a], [, b]) => b - a)
    .map(([path, count]) => ({
      path,
      count,
      intensity: count / max,
    }))
})

function cellBackground(intensity: number): string {
  const alpha = 0.15 + intensity * 0.85
  return `rgba(79, 70, 229, ${alpha.toFixed(2)})`
}

function cellColor(intensity: number): string {
  return intensity > 0.5 ? '#fff' : '#1e1b4b'
}
</script>

<template>
  <div class="heatmap">
    <p v-if="cells.length === 0" class="empty">No context.load events found.</p>
    <div v-else class="heatmap-grid">
      <div
        v-for="cell in cells"
        :key="cell.path"
        class="heatmap-cell"
        :style="{ background: cellBackground(cell.intensity), color: cellColor(cell.intensity) }"
        :title="`${cell.path} (${cell.count})`"
      >
        <span class="cell-path">{{ cell.path.split('/').pop() }}</span>
        <span class="cell-count">{{ cell.count }}</span>
      </div>
    </div>
  </div>
</template>

<style scoped>
.heatmap-grid {
  display: flex;
  flex-wrap: wrap;
  gap: 0.375rem;
}
.heatmap-cell {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: 0.5rem 0.75rem;
  border-radius: 0.375rem;
  min-width: 6rem;
  max-width: 12rem;
  cursor: default;
  overflow: hidden;
}
.cell-path {
  font-size: 0.75rem;
  font-weight: 600;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  max-width: 100%;
}
.cell-count {
  font-size: 0.65rem;
  opacity: 0.8;
}
.empty { color: #9ca3af; }
</style>
