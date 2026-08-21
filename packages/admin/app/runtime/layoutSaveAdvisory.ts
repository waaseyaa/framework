import type { AdminSurfaceSaveAdvisory } from '../contracts/adminSurface'
import type { PageBuilderSurfaceError } from '../contracts/pageBuilder'

/**
 * Layout-draft save-advisory review, client side (#2475).
 *
 * The page-builder command endpoint answers a held layout edit with `428` and
 * the allowlisted `meta.save_advisories` projection, and answers a receipt sent
 * to a gateway that cannot carry one with `501`. Both are machine codes; this
 * module is the only place the SPA reads them.
 *
 * Everything here is a *reader*. An acknowledgement token is a candidate-bound
 * receipt minted by the server: it is returned verbatim on the retry of the
 * exact candidate that produced it, and is never synthesized, rewritten,
 * persisted, or carried to another candidate.
 */

/** A layout edit is held for author review. */
export const LAYOUT_SAVE_ADVISORY_REQUIRED_CODE = 'SAVE_ADVISORY_ACKNOWLEDGEMENT_REQUIRED'

/** The deployment's layout-draft gateway cannot carry receipts at all. */
export const LAYOUT_SAVE_ADVISORY_UNSUPPORTED_CODE = 'SAVE_ADVISORY_UNSUPPORTED'

/** Matches the SaveContext acknowledgement bound the host enforces. */
const MAX_ADVISORIES = 32

const ACKNOWLEDGEMENT_PATTERN = /^[a-f0-9]{64}$/

function isSaveAdvisory(candidate: unknown): candidate is AdminSurfaceSaveAdvisory {
  if (typeof candidate !== 'object' || candidate === null) return false
  const { code, field, severity, message, acknowledgement } = candidate as Partial<AdminSurfaceSaveAdvisory>
  return typeof code === 'string' && code !== ''
    && typeof field === 'string' && field !== ''
    && severity === 'warning'
    && typeof message === 'string' && message !== ''
    && typeof acknowledgement === 'string'
    && ACKNOWLEDGEMENT_PATTERN.test(acknowledgement)
}

/**
 * The advisories a `428` carries, or `null` when this is not a reviewable
 * advisory response.
 *
 * A malformed projection is a refusal, not a partial prompt: one bad entry
 * rejects the whole list, so the editor can never present an author with a
 * review it cannot faithfully acknowledge.
 */
export function layoutSaveAdvisoriesFrom(
  error: PageBuilderSurfaceError | undefined,
): AdminSurfaceSaveAdvisory[] | null {
  if (!error || error.status !== 428 || error.code !== LAYOUT_SAVE_ADVISORY_REQUIRED_CODE) return null
  const candidates: unknown = error.meta?.save_advisories
  if (!Array.isArray(candidates) || candidates.length === 0 || candidates.length > MAX_ADVISORIES) return null
  const advisories: AdminSurfaceSaveAdvisory[] = []
  for (const candidate of candidates as unknown[]) {
    if (!isSaveAdvisory(candidate)) return null
    advisories.push(candidate)
  }
  return advisories
}

/** True when the deployment cannot carry acknowledgement receipts at all. */
export function isUnsupportedLayoutSaveAdvisory(error: PageBuilderSurfaceError | undefined): boolean {
  return error?.status === 501 && error.code === LAYOUT_SAVE_ADVISORY_UNSUPPORTED_CODE
}
