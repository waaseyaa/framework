import { expect } from 'vitest'

export type LifecycleBeat =
  | { event: 'dirty', dirty: boolean }
  | { event: 'saved' }
  | { event: 'failure', failure: unknown }

export function createLifecycleTimeline(): {
  timeline: LifecycleBeat[]
  attrs: {
    onDirty: (dirty: boolean) => void
    onSaved: () => void
    onFailure: (failure: unknown) => void
  }
} {
  const timeline: LifecycleBeat[] = []
  return {
    timeline,
    attrs: {
      onDirty: (dirty: boolean) => { timeline.push({ event: 'dirty', dirty }) },
      onSaved: () => { timeline.push({ event: 'saved' }) },
      onFailure: (failure: unknown) => { timeline.push({ event: 'failure', failure }) },
    },
  }
}

export function persistenceBeats(timeline: readonly LifecycleBeat[]): Array<'dirty:true' | 'dirty:false' | 'saved'> {
  const beats: Array<'dirty:true' | 'dirty:false' | 'saved'> = []
  for (const beat of timeline) {
    if (beat.event === 'saved') beats.push('saved')
    if (beat.event === 'dirty') beats.push(beat.dirty ? 'dirty:true' : 'dirty:false')
  }
  return beats
}

export function expectDirtyThenCleanThenSaved(timeline: readonly LifecycleBeat[]): void {
  expect(persistenceBeats(timeline)).toEqual(['dirty:true', 'dirty:false', 'saved'])
}

export function expectRemainsDirtyWithoutSaved(timeline: readonly LifecycleBeat[]): void {
  const beats = persistenceBeats(timeline)
  expect(beats).toContain('dirty:true')
  expect(beats).not.toContain('dirty:false')
  expect(beats).not.toContain('saved')
}
