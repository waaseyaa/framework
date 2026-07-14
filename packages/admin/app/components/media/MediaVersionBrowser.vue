<script setup lang="ts">
// MediaVersionBrowser — read-only table of all versions for a given media asset.
//
// DIR-005 (versioned-blob-media-abstraction-01KSEFTJ) WP04.
// Mirrors QueueJobRow / SchedulerTaskRow patterns (M4B WP01/WP02).
// @internal Parked until #1742 proves upload bytes persist across the live request boundary.

import { useMediaVersions } from '~/composables/useMediaVersions'
import { useLanguage } from '~/composables/useLanguage'
import type { MediaVersion } from '~/composables/useMediaVersions'

const props = defineProps<{ mediaUuid: string }>()

const { t } = useLanguage()
const { versions, total, loading, error, fetchVersions } = useMediaVersions()

onMounted(() => fetchVersions(props.mediaUuid))

/**
 * Format a Unix timestamp (seconds) as a locale date-time string.
 */
function formatTimestamp(ts: number): string {
  return new Date(ts * 1000).toLocaleString()
}

/**
 * Abbreviate a sha256 hex string to the first 12 chars for display.
 */
function shortHash(sha256: string): string {
  return sha256.slice(0, 12)
}

/**
 * Human-readable byte count.
 */
function formatBytes(bytes: number): string {
  if (bytes < 1024) {
    return `${bytes} B`
  }
  if (bytes < 1024 * 1024) {
    return `${(bytes / 1024).toFixed(1)} KB`
  }
  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`
}
</script>

<template>
  <div data-testid="media-version-browser">
    <h2>{{ t('media_versions_title') }}</h2>

    <div v-if="loading" class="loading" data-testid="media-version-loading">
      {{ t('loading') }}
    </div>

    <div v-else-if="error" class="error" data-testid="media-version-error">
      {{ error }}
    </div>

    <p
      v-else-if="versions.length === 0"
      class="empty-state"
      data-testid="media-version-empty"
    >
      {{ t('media_versions_empty') }}
    </p>

    <template v-else>
      <table class="entity-table" data-testid="media-version-table">
        <thead>
          <tr>
            <th>{{ t('media_version_col_vid') }}</th>
            <th>{{ t('media_version_col_mime') }}</th>
            <th>{{ t('media_version_col_size') }}</th>
            <th>{{ t('media_version_col_hash') }}</th>
            <th>{{ t('media_version_col_created') }}</th>
            <th>{{ t('media_version_col_creator') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr
            v-for="version in versions"
            :key="version.vid"
            data-testid="media-version-row"
          >
            <td>{{ version.vid }}</td>
            <td>
              <code>{{ version.mime }}</code>
            </td>
            <td>{{ formatBytes(version.size_bytes) }}</td>
            <td>
              <code :title="version.sha256">{{ shortHash(version.sha256) }}</code>
            </td>
            <td>{{ formatTimestamp(version.created_at) }}</td>
            <td>{{ version.created_by }}</td>
          </tr>
        </tbody>
      </table>

      <p class="meta">
        {{ t('media_versions_total', { total: String(total) }) }}
      </p>
    </template>
  </div>
</template>

<style scoped>
h2 {
  font-size: 16px;
  font-weight: 600;
  margin-bottom: 12px;
}
.meta {
  margin-top: 12px;
  color: var(--color-muted);
  font-size: 13px;
}
</style>
