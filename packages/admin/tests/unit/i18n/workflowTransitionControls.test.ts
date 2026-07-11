// packages/admin/tests/unit/i18n/workflowTransitionControls.test.ts
//
// WP-4 Task C: every new key introduced by the transition-controls UI
// (composable error fallback + SchemaList workflow-state badge column
// label) must exist in BOTH locales — both are statically imported, so a
// missing key leaks the raw key string into the UI.
import { describe, it, expect } from 'vitest'
import en from '../../../app/i18n/en.json'
import fr from '../../../app/i18n/fr.json'

const REQUIRED_KEYS = [
  'workflow_transition_error_generic',
  'workflow_state_column_label',
] as const

describe('workflow transition-controls i18n', () => {
  it.each(REQUIRED_KEYS)('en defines %s', (key) => {
    expect((en as Record<string, string>)[key]).toBeTruthy()
  })

  it.each(REQUIRED_KEYS)('fr defines %s', (key) => {
    expect((fr as Record<string, string>)[key]).toBeTruthy()
  })

  it('en and fr have matching key sets for transition controls', () => {
    const enKeys = REQUIRED_KEYS.filter(k => (en as Record<string, string>)[k])
    const frKeys = REQUIRED_KEYS.filter(k => (fr as Record<string, string>)[k])
    expect(enKeys).toEqual(frKeys)
  })
})
