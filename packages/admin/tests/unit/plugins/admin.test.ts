import { describe, it, expect } from 'vitest'
import { ADMIN_CONTRACT_VERSION } from '~/contracts'

describe('bootstrap version validation', () => {
  it('accepts matching contract version', () => {
    const bootstrap = { version: ADMIN_CONTRACT_VERSION }
    expect(bootstrap.version).toBe('1.0')
  })

  it('rejects mismatched contract version', () => {
    const bootstrap = { version: '2.0' }
    expect(bootstrap.version).not.toBe(ADMIN_CONTRACT_VERSION)
  })
})
