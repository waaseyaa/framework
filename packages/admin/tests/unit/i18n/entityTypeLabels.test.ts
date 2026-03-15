import { describe, it, expect } from 'vitest'
import en from '../../../app/i18n/en.json'

describe('entity type i18n labels', () => {
  it('has a label for the note entity type', () => {
    expect((en as Record<string, string>)['entity_type_note']).toBeTruthy()
  })

  it('has a label for the relationship entity type', () => {
    expect((en as Record<string, string>)['entity_type_relationship']).toBeTruthy()
  })
})
