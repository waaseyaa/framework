import type { AdminSurfaceErrorMeta } from './adminSurface'
import { TransportError } from './transport'

/**
 * Compile-time regression for the closed Admin error-meta contract.
 *
 * `AdminSurfaceErrorMeta` must stay a closed allowlist (no index signature).
 * `TransportError` must accept that type directly so the Admin transport does
 * not widen it back to `Record<string, unknown>`.
 */
type ClosedMetaIsNotAStringRecord = AdminSurfaceErrorMeta extends Record<string, unknown>
  ? never
  : true

export const closedAdminSurfaceErrorMeta: ClosedMetaIsNotAStringRecord = true

const closedMeta: AdminSurfaceErrorMeta = {
  save_advisories: [{
    code: 'RESERVED_ROUTE_VALUE',
    field: 'title',
    severity: 'warning',
    message: 'Review the fallback URL.',
    acknowledgement: 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
  }],
}

export const transportErrorAcceptsClosedMeta = new TransportError(
  428,
  'Precondition Required',
  undefined,
  undefined,
  'SAVE_ADVISORY_ACKNOWLEDGEMENT_REQUIRED',
  closedMeta,
)

type TransportErrorMetaIsClosed = TransportError extends { readonly meta?: AdminSurfaceErrorMeta }
  ? true
  : never

export const transportErrorMetaIsClosed: TransportErrorMetaIsClosed = true
