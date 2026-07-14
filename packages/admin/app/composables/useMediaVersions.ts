// useMediaVersions — admin SPA composable for the media version browser.
//
// DIR-005 (versioned-blob-media-abstraction-01KSEFTJ) WP04.
//
// Fetches `GET /api/media/{uuid}/versions` and `GET /api/media/{uuid}/versions/{vid}`
// from the PHP API layer (WP03). Read-only; no mutation surface (FR-010 download
// deferred; write surface is out of scope for this WP).
//
// Mirrors useScheduledTasks (M4B WP02) for shape and naming conventions.
// @internal Parked until #1742 proves upload bytes persist across the live request boundary.

/**
 * A single media version resource as returned by the API.
 */
export interface MediaVersion {
  vid: number
  media_uuid: string
  blob_uri: string
  mime: string
  size_bytes: number
  sha256: string
  created_at: number
  created_by: number
}

export interface MediaVersionsResponse {
  data: MediaVersion[]
  meta: { total: number }
}

export interface MediaVersionResponse {
  data: MediaVersion
}

export function useMediaVersions() {
  const { apiFetch } = useApi()
  const versions = ref<MediaVersion[]>([])
  const total = ref(0)
  const loading = ref(false)
  const error = ref<string | null>(null)

  async function fetchVersions(mediaUuid: string): Promise<void> {
    loading.value = true
    error.value = null
    try {
      const response = await apiFetch<MediaVersionsResponse>(
        `/api/media/${encodeURIComponent(mediaUuid)}/versions`,
      )
      versions.value = response.data ?? []
      total.value = response.meta?.total ?? versions.value.length
    } catch (e: unknown) {
      const err = e as { data?: { errors?: Array<{ detail?: string }> }, message?: string }
      error.value = err?.data?.errors?.[0]?.detail ?? err?.message ?? 'Failed to load media versions.'
    } finally {
      loading.value = false
    }
  }

  return { versions, total, loading, error, fetchVersions }
}
