<script setup lang="ts">
import type { CodifiedContextEvent } from '~/composables/useCodifiedContext'

defineProps<{ events: CodifiedContextEvent[] }>()

const expanded = ref<Set<string>>(new Set())

function toggle(id: string) {
  if (expanded.value.has(id)) {
    expanded.value.delete(id)
  } else {
    expanded.value.add(id)
  }
  // Trigger reactivity
  expanded.value = new Set(expanded.value)
}

function formatDate(iso: string): string {
  return new Date(iso).toLocaleTimeString()
}
</script>

<template>
  <div class="event-stream">
    <p v-if="events.length === 0" class="empty">No events recorded.</p>
    <table v-else class="data-table">
      <thead>
        <tr>
          <th>Time</th>
          <th>Type</th>
          <th>Details</th>
        </tr>
      </thead>
      <tbody>
        <template v-for="event in events" :key="event.id">
          <tr>
            <td class="mono">{{ formatDate(event.createdAt) }}</td>
            <td>
              <span class="event-type-badge">{{ event.eventType }}</span>
            </td>
            <td>
              <button class="btn-inline" @click="toggle(event.id)">
                {{ expanded.has(event.id) ? '▲ hide' : '▼ show' }}
              </button>
            </td>
          </tr>
          <tr v-if="expanded.has(event.id)" class="details-row">
            <td colspan="3">
              <pre class="json-pre">{{ JSON.stringify(event.data, null, 2) }}</pre>
            </td>
          </tr>
        </template>
      </tbody>
    </table>
  </div>
</template>

<style scoped>
.event-type-badge {
  display: inline-block;
  padding: 0.1em 0.5em;
  background: #ede9fe;
  color: #5b21b6;
  border-radius: 0.25rem;
  font-size: 0.8em;
  font-family: monospace;
}
.mono { font-family: monospace; font-size: 0.85em; white-space: nowrap; }
.btn-inline {
  background: none;
  border: none;
  cursor: pointer;
  color: #6b7280;
  font-size: 0.8em;
  padding: 0.1em 0.3em;
}
.btn-inline:hover { color: #111827; }
.details-row td { background: #f9fafb; padding: 0.5rem 1rem; }
.json-pre {
  margin: 0;
  font-size: 0.8em;
  white-space: pre-wrap;
  word-break: break-all;
  max-height: 12rem;
  overflow-y: auto;
}
.empty { color: #9ca3af; }
</style>
