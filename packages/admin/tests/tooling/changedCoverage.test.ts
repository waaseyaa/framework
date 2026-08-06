import { execFileSync, spawnSync } from 'node:child_process'
import { mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { resolve } from 'node:path'
import { afterEach, describe, expect, it } from 'vitest'

const adminRoot = resolve(import.meta.dirname, '../..')
const repoRoot = resolve(adminRoot, '../..')
let directory = ''

afterEach(() => {
  if (directory) rmSync(directory, { recursive: true, force: true })
})

describe('changed admin coverage ratchet', () => {
  it('passes at the threshold and fails below it', () => {
    directory = mkdtempSync(resolve(tmpdir(), 'waaseyaa-admin-coverage-'))
    const diff = resolve(directory, 'change.diff')
    const report = resolve(directory, 'coverage.json')
    writeFileSync(diff, 'diff --git a/packages/admin/app/example.ts b/packages/admin/app/example.ts\n+++ b/packages/admin/app/example.ts\n@@ -1,5 +1,5 @@\n')
    writeFileSync(report, JSON.stringify({
      [resolve(repoRoot, 'packages/admin/app/example.ts')]: {
        statementMap: {
          0: { start: { line: 1 }, end: { line: 1 } },
          1: { start: { line: 2 }, end: { line: 2 } },
          2: { start: { line: 3 }, end: { line: 3 } },
          3: { start: { line: 4 }, end: { line: 4 } },
          4: { start: { line: 5 }, end: { line: 5 } },
        },
        s: { 0: 1, 1: 1, 2: 1, 3: 1, 4: 0 },
      },
    }))
    const env = {
      ...process.env,
      ADMIN_COVERAGE_DIFF_FILE: diff,
      ADMIN_COVERAGE_REPORT: report,
      CHANGED_COVERAGE_THRESHOLD: '80',
    }

    const output = execFileSync(process.execPath, ['scripts/check-changed-coverage.mjs'], {
      cwd: adminRoot,
      env,
      encoding: 'utf8',
    })
    expect(output).toContain('4/5 executable changed statements covered (80.00%')

    const data = JSON.parse(readFileSync(report, 'utf8'))
    data[resolve(repoRoot, 'packages/admin/app/example.ts')].s[3] = 0
    writeFileSync(report, JSON.stringify(data))
    const failed = spawnSync(process.execPath, ['scripts/check-changed-coverage.mjs'], {
      cwd: adminRoot,
      env,
      encoding: 'utf8',
    })
    expect(failed.status).toBe(1)
    expect(failed.stdout).toContain('60.00%')
  })
})
