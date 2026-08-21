import { describe, expect, it } from 'vitest'
import type { PageBuilderSurfaceError } from '~/contracts/pageBuilder'
import {
  isUnsupportedLayoutSaveAdvisory,
  layoutSaveAdvisoriesFrom,
} from '~/runtime/layoutSaveAdvisory'

const token = 'a'.repeat(64)

function held(save_advisories: unknown[]): PageBuilderSurfaceError {
  return {
    status: 428,
    title: 'Precondition Required',
    detail: 'This change needs review.',
    code: 'SAVE_ADVISORY_ACKNOWLEDGEMENT_REQUIRED',
    meta: { save_advisories: save_advisories as never },
  }
}

const advisory = {
  code: 'RESERVED_ROUTE_VALUE',
  field: 'slug',
  severity: 'warning',
  message: 'This slug is reserved for a system route.',
  acknowledgement: token,
}

describe('layoutSaveAdvisoriesFrom', () => {
  it('reads the advisories a held layout edit carries', () => {
    expect(layoutSaveAdvisoriesFrom(held([advisory]))).toEqual([advisory])
  })

  it('is not a review for an absent, unrelated, or differently coded failure', () => {
    expect(layoutSaveAdvisoriesFrom(undefined)).toBeNull()
    expect(layoutSaveAdvisoriesFrom({ status: 409, title: 'Page changed' })).toBeNull()
    expect(layoutSaveAdvisoriesFrom({ ...held([advisory]), status: 422 })).toBeNull()
    expect(layoutSaveAdvisoriesFrom({ ...held([advisory]), code: 'SOMETHING_ELSE' })).toBeNull()
    expect(layoutSaveAdvisoriesFrom({ status: 428, title: 'Precondition Required', code: 'SAVE_ADVISORY_ACKNOWLEDGEMENT_REQUIRED' })).toBeNull()
  })

  it('refuses the whole list when any entry is malformed, so no partial review is shown', () => {
    const malformed: unknown[] = [
      { ...advisory, acknowledgement: 'A'.repeat(64) },
      { ...advisory, acknowledgement: `${token}0` },
      { ...advisory, acknowledgement: 'not-a-token' },
      { ...advisory, severity: 'error' },
      { ...advisory, message: '' },
      { ...advisory, field: '' },
      { ...advisory, code: '' },
      { ...advisory, acknowledgement: undefined },
      'a string',
      null,
    ]
    for (const candidate of malformed) {
      expect(layoutSaveAdvisoriesFrom(held([candidate]))).toBeNull()
    }
    // One good entry never rescues a bad sibling.
    expect(layoutSaveAdvisoriesFrom(held([advisory, { ...advisory, severity: 'error' }]))).toBeNull()
  })

  it('refuses an empty projection and anything past the acknowledgement bound', () => {
    expect(layoutSaveAdvisoriesFrom(held([]))).toBeNull()
    const many = Array.from({ length: 33 }, (_, index) => ({
      ...advisory,
      acknowledgement: index.toString(16).padStart(64, '0'),
    }))
    expect(layoutSaveAdvisoriesFrom(held(many))).toBeNull()
    expect(layoutSaveAdvisoriesFrom(held(many.slice(0, 32)))).toHaveLength(32)
  })

  it('returns the received tokens unchanged and does not share the wire array', () => {
    const wire = [advisory]
    const advisories = layoutSaveAdvisoriesFrom(held(wire))

    expect(advisories?.[0]?.acknowledgement).toBe(token)
    expect(advisories).not.toBe(wire)
  })
})

describe('isUnsupportedLayoutSaveAdvisory', () => {
  it('recognises only the declared capability refusal', () => {
    expect(isUnsupportedLayoutSaveAdvisory({
      status: 501,
      title: 'Save advisory acknowledgement unsupported',
      code: 'SAVE_ADVISORY_UNSUPPORTED',
    })).toBe(true)
    expect(isUnsupportedLayoutSaveAdvisory({ status: 501, title: 'Not Implemented' })).toBe(false)
    expect(isUnsupportedLayoutSaveAdvisory({ status: 500, title: 'x', code: 'SAVE_ADVISORY_UNSUPPORTED' })).toBe(false)
    expect(isUnsupportedLayoutSaveAdvisory(undefined)).toBe(false)
  })
})
