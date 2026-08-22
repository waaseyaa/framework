// useWorkflowTransitions — admin SPA composable for the CW-v1 transition UI
// (WP-4 Task C, #1920). Reads GET /api/{entityType}/{id}/workflow/transitions
// (the sanctioned UI read side — group- and permission-filtered, per WP-2/WP-3)
// and posts /api/{entityType}/{id}/workflow/transition. The POST is an
// aggregate mutation: WorkflowTransitionController requires a strong If-Match
// entity mutation ETag and answers 428 without one. The only authoritative
// validator for that POST is `meta.mutation_token` from the discovery response,
// which the controller computes from the same working copy the transition
// targets; after a committed transition the apply response carries the
// successor token. This composable therefore never synthesizes a validator and
// never reuses one it has not just observed. Modeled on
// useWorkflowDefinitions.ts: always goes through useApi().apiFetch, never raw
// $fetch, so the canonical root /api base is independent of the admin mount.

export interface WorkflowTransitionItem {
  id: string
  label: string
  to: string
}

export interface WorkflowTransitionHistoryItem {
  transition: string
  from: string
  to: string
  uid: string
  at: string
}

interface WorkflowTransitionsResponse {
  data: WorkflowTransitionItem[]
  meta?: {
    workflow_state?: string | null
    workflow_history?: WorkflowTransitionHistoryItem[]
    mutation_token?: string
  }
}

export interface WorkflowTransitionApplyResult {
  transition: string
  from: string
  to: string
  public_changed: boolean
}

interface WorkflowTransitionApplyData {
  transition: string
  from: string
  to: string
  public_changed?: boolean
}

interface WorkflowTransitionApplyResponse {
  data: WorkflowTransitionApplyData
  meta?: {
    mutation_token?: string
  }
}

export type WorkflowTransitionErrorKind =
  | 'forbidden'
  | 'not_found'
  | 'malformed_response'
  | 'network'
  | 'precondition'
  | 'server'

/**
 * The held validator is missing or was refused by the server. The caller must
 * re-read the transitions before trying again; retrying without a freshly
 * observed validator would defeat the mutation fence, so this composable never
 * does it on the caller's behalf.
 */
export class WorkflowTransitionPreconditionError extends Error {
  constructor(message: string) {
    super(message)
    this.name = 'WorkflowTransitionPreconditionError'
  }
}

function readMutationToken(value: unknown): string | null {
  if (typeof value !== 'object' || value === null) return null
  const token = (value as { mutation_token?: unknown }).mutation_token
  return typeof token === 'string' && token !== '' ? token : null
}

function subjectOf(entityType: string, id: string): string {
  return `${entityType}:${id}`
}

export function useWorkflowTransitions() {
  const { apiFetch } = useApi()

  const transitions = ref<WorkflowTransitionItem[]>([])
  const state = ref<string | null>(null)
  const history = ref<WorkflowTransitionHistoryItem[]>([])
  const loading = ref(false)
  const error = ref<string | null>(null)
  const errorKind = ref<WorkflowTransitionErrorKind | null>(null)
  // The validator observed by the most recent successful discovery, and the
  // exact entity it belongs to. Both are cleared together so a token can never
  // be carried across entities or survive a failed read.
  const mutationToken = ref<string | null>(null)
  const mutationTokenSubject = ref<string | null>(null)

  function forgetMutationToken(): void {
    mutationToken.value = null
    mutationTokenSubject.value = null
  }

  async function fetchTransitions(
    entityType: string,
    id: string,
  ): Promise<{ transitions: WorkflowTransitionItem[]; state: string | null; history: WorkflowTransitionHistoryItem[] }> {
    loading.value = true
    error.value = null
    errorKind.value = null
    try {
      const response = await apiFetch<WorkflowTransitionsResponse>(
        `/api/${entityType}/${encodeURIComponent(id)}/workflow/transitions`,
      )

      if (!isWorkflowTransitionsResponse(response)) {
        throw new WorkflowTransitionResponseError()
      }

      transitions.value = response.data
      state.value = response.meta?.workflow_state ?? null
      history.value = response.meta?.workflow_history ?? []
      // The controller omits the token when no transition is available, which
      // is exactly when there is nothing to apply.
      mutationToken.value = readMutationToken(response.meta)
      mutationTokenSubject.value = mutationToken.value === null ? null : subjectOf(entityType, id)
    } catch (e: unknown) {
      transitions.value = []
      state.value = null
      history.value = []
      forgetMutationToken()

      if (e instanceof WorkflowTransitionResponseError) {
        errorKind.value = 'malformed_response'
        error.value = 'Workflow discovery returned an invalid response.'
        return { transitions: transitions.value, state: state.value, history: history.value }
      }

      const err = e as { data?: unknown; message?: string; response?: { status?: number }; statusCode?: number; cause?: unknown }
      const statusCode = err?.response?.status ?? err?.statusCode ?? 0
      if (statusCode === 404) {
        // Entity missing or not viewable — the canonical "no transitions" shape.
        // Render nothing, not an error (R8 oracle standard — see WP-4 plan).
        errorKind.value = 'not_found'
      } else {
        errorKind.value = classifyWorkflowTransitionError(err, statusCode)
        error.value = jsonApiErrorDetail(err?.data) ?? err?.message ?? 'Failed to load workflow transitions.'
      }
    } finally {
      loading.value = false
    }
    return { transitions: transitions.value, state: state.value, history: history.value }
  }

  async function applyTransition(
    entityType: string,
    id: string,
    transitionId: string,
  ): Promise<WorkflowTransitionApplyResult> {
    // Apply only against a validator this composable observed for this exact
    // entity. Posting without one is refused locally rather than sent, because
    // the server would answer 428 and because a caller that has not read the
    // current transitions cannot know what it is transitioning from.
    if (mutationToken.value === null || mutationTokenSubject.value !== subjectOf(entityType, id)) {
      throw new WorkflowTransitionPreconditionError(
        'Read the current workflow transitions before applying one.',
      )
    }

    let response: WorkflowTransitionApplyResponse
    try {
      response = await apiFetch<WorkflowTransitionApplyResponse>(
        `/api/${entityType}/${encodeURIComponent(id)}/workflow/transition`,
        {
          method: 'POST',
          body: { transition: transitionId },
          // Strong entity mutation ETag, the form EntityMutationToken accepts.
          headers: { 'If-Match': `"${mutationToken.value}"` },
        },
      )
    } catch (e: unknown) {
      const err = e as { response?: { status?: number }; statusCode?: number }
      const statusCode = err?.response?.status ?? err?.statusCode ?? 0
      // 412 means the entity moved under us and 428 means the server did not
      // accept what we sent as a precondition. In both cases the held validator
      // is worthless: drop it so the next attempt must re-read the transitions.
      // Never retry here by weakening or omitting the fence.
      if (statusCode === 412 || statusCode === 428) {
        forgetMutationToken()
      }
      throw e
    }

    if (!isWorkflowTransitionApplyResponse(response)) {
      forgetMutationToken()
      throw new WorkflowTransitionResponseError()
    }

    // Adopt the successor the server just issued, so a second transition in the
    // same session is fenced by the committed state rather than by the token
    // that has now been consumed. If the response carries none, hold nothing.
    const successor = readMutationToken(response.meta)
    mutationToken.value = successor
    mutationTokenSubject.value = successor === null ? null : subjectOf(entityType, id)

    return {
      transition: response.data.transition,
      from: response.data.from,
      to: response.data.to,
      // Older API packages and application-provided controllers may omit the
      // additive refresh signal after a committed transition. Absence is not
      // failure; hosts then refresh public rendering as they did before the
      // signal existed.
      public_changed: response.data.public_changed ?? true,
    }
  }

  return { transitions, state, history, loading, error, errorKind, mutationToken, fetchTransitions, applyTransition }
}

function isWorkflowTransitionApplyResponse(value: unknown): value is WorkflowTransitionApplyResponse {
  if (typeof value !== 'object' || value === null) return false
  const data = (value as { data?: unknown }).data
  if (typeof data !== 'object' || data === null) return false
  const record = data as Record<string, unknown>
  if (
    typeof record.transition !== 'string'
    || typeof record.from !== 'string'
    || typeof record.to !== 'string'
  ) {
    return false
  }
  // The successor validator is additive: an API package that predates it simply
  // carries no meta, and the caller then holds nothing rather than something stale.
  const meta = (value as { meta?: unknown }).meta
  if (meta !== undefined) {
    if (typeof meta !== 'object' || meta === null) return false
    const token = (meta as Record<string, unknown>).mutation_token
    if (token !== undefined && token !== null && typeof token !== 'string') return false
  }
  return record.public_changed === undefined || typeof record.public_changed === 'boolean'
}

class WorkflowTransitionResponseError extends Error {}

function isWorkflowTransitionsResponse(value: unknown): value is WorkflowTransitionsResponse {
  if (typeof value !== 'object' || value === null || !Array.isArray((value as WorkflowTransitionsResponse).data)) {
    return false
  }

  const response = value as WorkflowTransitionsResponse
  const validTransitions = response.data.every((transition) =>
    typeof transition === 'object'
    && transition !== null
    && typeof transition.id === 'string'
    && typeof transition.label === 'string'
    && typeof transition.to === 'string',
  )
  const history = response.meta?.workflow_history
  const validHistory = history === undefined || (Array.isArray(history) && history.every((entry) =>
    typeof entry === 'object'
    && entry !== null
    && typeof entry.transition === 'string'
    && typeof entry.from === 'string'
    && typeof entry.to === 'string'
    && typeof entry.uid === 'string'
    && typeof entry.at === 'string'
  ))

  return validTransitions && validHistory
}

function jsonApiErrorDetail(value: unknown): string | null {
  if (typeof value !== 'object' || value === null) return null

  const directErrors = (value as { errors?: unknown }).errors
  if (Array.isArray(directErrors) && typeof directErrors[0]?.detail === 'string') {
    return directErrors[0].detail
  }

  return jsonApiErrorDetail((value as { data?: unknown }).data)
}

function hasTypeErrorCause(value: unknown): boolean {
  let current = value
  const seen = new Set<unknown>()

  while (typeof current === 'object' && current !== null && !seen.has(current)) {
    if (current instanceof TypeError) return true
    seen.add(current)
    current = (current as { cause?: unknown }).cause
  }

  return false
}

export function classifyWorkflowTransitionError(
  error: unknown,
  statusCode: number,
): Exclude<WorkflowTransitionErrorKind, 'not_found' | 'malformed_response'> {
  if (statusCode === 403) return 'forbidden'
  if (statusCode === 412 || statusCode === 428) return 'precondition'
  if (statusCode === 0 || hasTypeErrorCause(error)) return 'network'
  return 'server'
}
