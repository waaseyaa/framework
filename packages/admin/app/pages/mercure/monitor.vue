<script setup lang="ts">
import { useMercureMonitor } from '~/composables/useMercureMonitor'
import { useLanguage } from '~/composables/useLanguage'

const { t } = useLanguage()
const {
  channels,
  events,
  subscribers,
  loading,
  error,
  selectedChannels,
  fetchEvents,
  refresh,
} = useMercureMonitor()

onMounted(() => refresh())

const { appName } = useAdminConfig()
useHead({ title: computed(() => `${t('mercure_monitor_title')} | ${appName}`) })

function toggleChannel(channel: string): void {
  const idx = selectedChannels.value.indexOf(channel)
  if (idx === -1) {
    selectedChannels.value = [...selectedChannels.value, channel]
  } else {
    selectedChannels.value = selectedChannels.value.filter(c => c !== channel)
  }
  fetchEvents(selectedChannels.value)
}

function isChannelSelected(channel: string): boolean {
  return selectedChannels.value.length === 0 || selectedChannels.value.includes(channel)
}

function formatDate(iso: string | null): string {
  if (iso === null || iso === '') return '—'
  try {
    return new Date(iso).toLocaleString()
  } catch {
    return iso
  }
}
</script>

<template>
  <div>
    <div class="page-header">
      <h1>{{ t('mercure_monitor_title') }}</h1>
      <button
        type="button"
        class="btn"
        data-testid="mercure-refresh"
        :disabled="loading"
        @click="refresh()"
      >
        {{ t('mercure_monitor_refresh') }}
      </button>
    </div>

    <div v-if="loading" class="loading">{{ t('loading') }}</div>
    <div v-if="error" class="error" data-testid="mercure-error">{{ error }}</div>

    <!-- Channels section -->
    <section class="monitor-section">
      <h2>{{ t('mercure_monitor_channels_heading') }}</h2>

      <p v-if="channels.length === 0 && !loading" class="empty-state" data-testid="mercure-channels-empty">
        {{ t('mercure_monitor_channels_empty') }}
      </p>

      <div v-else class="filter-chips" data-testid="mercure-channel-filter">
        <button
          v-for="row in channels"
          :key="row.channel"
          type="button"
          class="chip"
          :class="{ active: isChannelSelected(row.channel) && selectedChannels.length > 0 }"
          :data-testid="`mercure-channel-chip-${row.channel}`"
          @click="toggleChannel(row.channel)"
        >
          {{ row.channel }} ({{ row.eventCount24h }})
        </button>
      </div>

      <table v-if="channels.length > 0" class="entity-table" data-testid="mercure-channels-table">
        <thead>
          <tr>
            <th>{{ t('mercure_monitor_col_channel') }}</th>
            <th>{{ t('mercure_monitor_col_event_count_24h') }}</th>
            <th>{{ t('mercure_monitor_col_last_event_at') }}</th>
            <th>{{ t('mercure_monitor_col_last_event_name') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="row in channels" :key="row.channel" data-testid="mercure-channel-row">
            <td><code>{{ row.channel }}</code></td>
            <td>{{ row.eventCount24h }}</td>
            <td>{{ formatDate(row.lastEventAt) }}</td>
            <td>{{ row.lastEventName ?? '—' }}</td>
          </tr>
        </tbody>
      </table>
    </section>

    <!-- Recent events section -->
    <section class="monitor-section">
      <h2>{{ t('mercure_monitor_events_heading') }}</h2>

      <p v-if="events.length === 0 && !loading" class="empty-state" data-testid="mercure-events-empty">
        {{ t('mercure_monitor_events_empty') }}
      </p>

      <table v-else class="entity-table" data-testid="mercure-events-table">
        <thead>
          <tr>
            <th>{{ t('mercure_monitor_col_id') }}</th>
            <th>{{ t('mercure_monitor_col_channel') }}</th>
            <th>{{ t('mercure_monitor_col_event') }}</th>
            <th>{{ t('mercure_monitor_col_created_at') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="row in events" :key="row.id" data-testid="mercure-event-row">
            <td><code>{{ row.id }}</code></td>
            <td><code>{{ row.channel }}</code></td>
            <td>{{ row.event }}</td>
            <td>{{ formatDate(row.createdAt) }}</td>
          </tr>
        </tbody>
      </table>
    </section>

    <!-- Active subscribers section -->
    <section class="monitor-section">
      <h2>{{ t('mercure_monitor_subscribers_heading') }}</h2>

      <p v-if="subscribers.length === 0 && !loading" class="empty-state" data-testid="mercure-subscribers-empty">
        {{ t('mercure_monitor_subscribers_empty') }}
      </p>

      <table v-else class="entity-table" data-testid="mercure-subscribers-table">
        <thead>
          <tr>
            <th>{{ t('mercure_monitor_col_account') }}</th>
            <th>{{ t('mercure_monitor_col_channels') }}</th>
            <th>{{ t('mercure_monitor_col_connected_since') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="(row, idx) in subscribers" :key="idx" data-testid="mercure-subscriber-row">
            <td>
              <span v-if="row.accountLabel !== null">{{ row.accountLabel }}</span>
              <span v-else-if="row.accountId === 0" class="muted">{{ t('mercure_monitor_anonymous') }}</span>
              <span v-else class="muted">#{{ row.accountId }}</span>
            </td>
            <td>
              <span v-for="ch in row.channels" :key="ch" class="channel-chip">{{ ch }}</span>
            </td>
            <td>{{ formatDate(row.connectedSince) }}</td>
          </tr>
        </tbody>
      </table>
    </section>
  </div>
</template>

<style scoped>
.page-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 16px;
}
.monitor-section {
  margin-bottom: 32px;
}
.monitor-section h2 {
  font-size: 16px;
  font-weight: 600;
  margin-bottom: 10px;
}
.filter-chips {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  margin-bottom: 12px;
}
.chip {
  appearance: none;
  border: 1px solid var(--color-border, #d1d5db);
  background: var(--color-surface, #fff);
  color: var(--color-text, #111827);
  padding: 4px 10px;
  border-radius: 999px;
  font-size: 13px;
  cursor: pointer;
}
.chip.active {
  background: var(--color-primary, #0f766e);
  color: #fff;
  border-color: var(--color-primary, #0f766e);
}
.channel-chip {
  display: inline-block;
  padding: 1px 6px;
  margin-right: 4px;
  border-radius: 4px;
  font-size: 12px;
  background: var(--color-bg, #f9fafb);
  border: 1px solid var(--color-border, #d1d5db);
}
.muted {
  color: var(--color-muted, #6b7280);
  font-size: 13px;
}
</style>
