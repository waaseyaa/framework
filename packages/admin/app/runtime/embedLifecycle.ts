export const EMBED_LIFECYCLE_SCHEMA = 'waaseyaa.admin.embed.lifecycle.v1' as const

export type EmbedSurface = 'entity-editor' | 'page-builder'
export type EmbedFailureKind = 'session-expired' | 'permission-denied' | 'conflict' | 'network' | 'server'

export interface EmbedFailure {
  kind: EmbedFailureKind
  status?: number
}

interface EmbedIdentity {
  surface: EmbedSurface
  entityId?: string
  entityType?: string
  surfaceId?: string
}

export type EmbedLifecyclePayload = EmbedIdentity & (
  | { event: 'ready' | 'saved' | 'deleted' }
  | { event: 'dirty', dirty: boolean }
  | { event: 'failure', failure: EmbedFailure }
)

export type EmbedLifecycleMessage = EmbedLifecyclePayload & {
  schema: typeof EMBED_LIFECYCLE_SCHEMA
}

function finiteStatus(value: unknown): number | undefined {
  const number = Number(value)
  return Number.isInteger(number) && number >= 100 && number <= 599 ? number : undefined
}

function statusFrom(value: unknown, seen = new Set<unknown>()): number | undefined {
  if (typeof value !== 'object' || value === null || seen.has(value)) return undefined
  seen.add(value)
  const candidate = value as Record<string, unknown>
  const direct = finiteStatus(candidate.status) ?? finiteStatus(candidate.statusCode)
  if (direct !== undefined) return direct
  if (Array.isArray(candidate.errors)) {
    for (const error of candidate.errors) {
      const status = statusFrom(error, seen)
      if (status !== undefined) return status
    }
  }
  return statusFrom(candidate.response, seen)
    ?? statusFrom(candidate.data, seen)
    ?? statusFrom(candidate.cause, seen)
}

function isNetworkFailure(value: unknown): boolean {
  let current: unknown = value
  const seen = new Set<unknown>()
  while (typeof current === 'object' && current !== null && !seen.has(current)) {
    if (current instanceof TypeError) return true
    seen.add(current)
    current = (current as Record<string, unknown>).cause
  }
  return false
}

export function classifyEmbedFailure(value: unknown): EmbedFailure {
  const status = statusFrom(value)
  if (status === 401) return { kind: 'session-expired', status }
  if (status === 403) return { kind: 'permission-denied', status }
  if (status === 409) return { kind: 'conflict', status }
  if (status !== undefined) return { kind: 'server', status }
  if (isNetworkFailure(value)) return { kind: 'network' }
  return { kind: 'server' }
}

export function postEmbedLifecycle(payload: EmbedLifecyclePayload, target: Window = window): void {
  if (target.parent === target) return
  const identity: EmbedIdentity = {
    surface: payload.surface,
    ...(payload.entityType !== undefined ? { entityType: payload.entityType } : {}),
    ...(payload.surfaceId !== undefined ? { surfaceId: payload.surfaceId } : {}),
    ...(payload.entityId !== undefined ? { entityId: payload.entityId } : {}),
  }
  const message: EmbedLifecycleMessage = payload.event === 'dirty'
    ? { schema: EMBED_LIFECYCLE_SCHEMA, event: 'dirty', dirty: payload.dirty, ...identity }
    : payload.event === 'failure'
      ? {
          schema: EMBED_LIFECYCLE_SCHEMA,
          event: 'failure',
          failure: {
            kind: payload.failure.kind,
            ...(payload.failure.status !== undefined ? { status: payload.failure.status } : {}),
          },
          ...identity,
        }
      : { schema: EMBED_LIFECYCLE_SCHEMA, event: payload.event, ...identity }
  target.parent.postMessage(message, target.location.origin)
}

function decodePathSegment(value: string): string | null {
  try {
    const decoded = decodeURIComponent(value)
    return decoded === '' ? null : decoded
  } catch {
    return null
  }
}

export function embedIdentityFromPath(pathname: string, adminBase: string): EmbedIdentity | null {
  const base = adminBase.replace(/\/+$/, '')
  const relative = pathname.startsWith(`${base}/`) ? pathname.slice(base.length) : pathname
  const parts = relative.split('/').filter(Boolean)
  if (parts.length !== 3) return null
  const first = decodePathSegment(parts[1]!)
  const second = decodePathSegment(parts[2]!)
  if (first === null || second === null) return null
  if (parts[0] === 'entity-editor-embed') {
    return { surface: 'entity-editor', entityType: first, ...(second === 'create' ? {} : { entityId: second }) }
  }
  if (parts[0] === 'page-builder-embed') {
    return { surface: 'page-builder', surfaceId: first, entityId: second }
  }
  return null
}

export function postEmbedBootstrapFailure(
  failure: EmbedFailure,
  pathname: string,
  adminBase: string,
  target: Window = window,
): void {
  const identity = embedIdentityFromPath(pathname, adminBase)
  if (identity !== null) postEmbedLifecycle({ event: 'failure', failure, ...identity }, target)
}
