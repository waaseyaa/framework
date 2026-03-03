// packages/admin/tests/unit/composables/useLanguage.test.ts
import { describe, it, expect } from 'vitest'
import { useLanguage } from '~/composables/useLanguage'

describe('useLanguage.t', () => {
  it('returns the English translation for a known key', () => {
    const { t } = useLanguage()
    // 'dashboard' is defined in app/i18n/en.json
    expect(t('dashboard')).not.toBe('dashboard')
    expect(typeof t('dashboard')).toBe('string')
    expect(t('dashboard').length).toBeGreaterThan(0)
  })

  it('falls back to the key itself for an unknown key', () => {
    const { t } = useLanguage()
    expect(t('__nonexistent_key_xyz__')).toBe('__nonexistent_key_xyz__')
  })

  it('interpolates replacement tokens', () => {
    const { t } = useLanguage()
    // If no key uses {token} in en.json, test the interpolation directly:
    // t() replaces {token} with the replacement value.
    // We test with a raw call: key falls back to itself, replacements applied.
    const result = t('Hello {name}', { name: 'World' })
    expect(result).toBe('Hello World')
  })
})
