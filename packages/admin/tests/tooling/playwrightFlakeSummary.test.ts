import { describe, expect, it } from 'vitest'
import { formatSummary, summarizePlaywrightReport } from '../../scripts/summarize-playwright-flakes.mjs'

function test(status: string, results: string[]) {
  return { status, results: results.map(result => ({ status: result })) }
}

describe('Playwright retry evidence', () => {
  it('distinguishes clean, recovered, terminal, and skipped outcomes in nested suites', () => {
    const report = {
      suites: [{
        specs: [{ tests: [test('expected', ['passed']), test('flaky', ['failed', 'passed'])] }],
        suites: [{ specs: [{ tests: [test('unexpected', ['timedOut', 'failed']), test('skipped', ['skipped'])] }] }],
      }],
    }

    expect(summarizePlaywrightReport(report)).toEqual({
      total: 4,
      passed: 1,
      flaky: 1,
      failed: 1,
      skipped: 1,
      firstAttemptFailures: 2,
      recoveredRetries: 1,
    })
  })

  it('renders the retry counts for the CI job summary', () => {
    const markdown = formatSummary({
      total: 2, passed: 1, flaky: 1, failed: 0, skipped: 0,
      firstAttemptFailures: 1, recoveredRetries: 1,
    })

    expect(markdown).toContain('Playwright retry evidence')
    expect(markdown).toContain('| 2 | 1 | 1 | 0 | 0 | 1 | 1 |')
  })
})
