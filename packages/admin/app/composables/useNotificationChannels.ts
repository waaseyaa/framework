// useNotificationChannels — admin SPA composable for the notifications dashboard (M4C WP01).
//
// Fetches `GET /api/notification/channels` to list registered channels, and
// posts `POST /api/notification/channels/{type}/test` to fire a synthetic test
// through one channel. Mirrors `useQueueJobs` / `useScheduledTasks` (M4B) for
// shape and naming.

export interface NotificationChannel {
  type: string
  class: string
}

export interface NotificationChannelsResponse {
  data: NotificationChannel[]
}

export interface NotificationTestResult {
  type: string
  status: 'success' | 'failed'
  message: string
  exception_class?: string
}

export function useNotificationChannels() {
  const { apiFetch } = useApi()
  const channels = ref<NotificationChannel[]>([])
  const loading = ref(false)
  const error = ref<string | null>(null)
  const lastTestResult = ref<NotificationTestResult | null>(null)

  async function fetchChannels(): Promise<void> {
    loading.value = true
    error.value = null
    try {
      const response = await apiFetch<NotificationChannelsResponse>(
        '/api/notification/channels',
      )
      channels.value = response.data ?? []
    } catch (e: unknown) {
      const err = e as { data?: { errors?: Array<{ detail?: string }> }, message?: string }
      error.value = err?.data?.errors?.[0]?.detail ?? err?.message ?? 'Failed to load notification channels.'
    } finally {
      loading.value = false
    }
  }

  /**
   * POST /api/notification/channels/{type}/test
   *
   * Resolves with the parsed envelope on both success and failure responses.
   * The backend extracts `\Throwable` into `{message, exception_class}` before
   * serialising so the JSON body always has scalar values.
   */
  async function testChannel(type: string): Promise<NotificationTestResult> {
    error.value = null
    try {
      const response = await apiFetch<NotificationTestResult>(
        `/api/notification/channels/${encodeURIComponent(type)}/test`,
        { method: 'POST' },
      )
      lastTestResult.value = response

      return response
    } catch (e: unknown) {
      const err = e as {
        data?: NotificationTestResult & { errors?: Array<{ detail?: string }> }
        message?: string
      }
      // 500 responses with the structured envelope ({type, status, message,
      // exception_class}) surface via $fetch's `data` field. Prefer those
      // when present so the UI can render the typed result; otherwise fall
      // back to a synthetic failure envelope.
      if (err?.data?.status === 'failed' && typeof err.data.type === 'string') {
        lastTestResult.value = {
          type: err.data.type,
          status: 'failed',
          message: err.data.message,
          ...(err.data.exception_class !== undefined ? { exception_class: err.data.exception_class } : {}),
        }
      } else {
        const message = err?.data?.errors?.[0]?.detail ?? err?.message ?? 'Failed to send test notification.'
        lastTestResult.value = { type, status: 'failed', message }
        error.value = message
      }

      return lastTestResult.value
    }
  }

  return { channels, loading, error, lastTestResult, fetchChannels, testChannel }
}
