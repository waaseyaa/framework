<script setup lang="ts">
// Media versions page — lists all versions for a given media asset.
//
// Route: /media/{uuid}/versions
// DIR-005 (versioned-blob-media-abstraction-01KSEFTJ) WP04.
// @internal Parked until #1742 proves upload bytes persist across the live request boundary.

import { useLanguage } from '~/composables/useLanguage'

const { t } = useLanguage()
const route = useRoute()
const uuid = computed(() => String(route.params.uuid))

const { appName } = useAdminConfig()
useHead({ title: computed(() => `${t('media_versions_title')} | ${appName}`) })
</script>

<template>
  <div>
    <div class="page-header">
      <h1>{{ t('media_versions_title') }}</h1>
    </div>

    <MediaVersionBrowser :media-uuid="uuid" />
  </div>
</template>
