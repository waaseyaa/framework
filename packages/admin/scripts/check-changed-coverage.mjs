#!/usr/bin/env node

import { execFileSync } from 'node:child_process'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'

const root = resolve(import.meta.dirname, '../../..')
const reportPath = resolve(process.env.ADMIN_COVERAGE_REPORT || 'coverage/coverage-final.json')
const base = process.env.COVERAGE_BASE_SHA || ''
const diffFile = process.env.ADMIN_COVERAGE_DIFF_FILE || ''
const threshold = Number(process.env.CHANGED_COVERAGE_THRESHOLD || 80)

if (!base && !diffFile) {
  console.log('changed-admin-coverage: no base SHA supplied; report published without a diff ratchet.')
  process.exit(0)
}
if (!Number.isFinite(threshold) || threshold < 0 || threshold > 100) {
  throw new Error('CHANGED_COVERAGE_THRESHOLD must be between 0 and 100')
}

const diff = diffFile
  ? readFileSync(resolve(diffFile), 'utf8')
  : execFileSync(
      resolve(root, 'bin/git'),
      ['-C', root, 'diff', '--unified=0', `${base}...HEAD`, '--', 'packages/admin/app'],
      { encoding: 'utf8' },
    )
const changed = new Map()
let path = null
for (const line of diff.split(/\r?\n/)) {
  const file = line.match(/^\+\+\+ b\/(packages\/admin\/app\/.+\.(?:ts|vue))$/)
  if (file) {
    path = file[1]
    continue
  }
  const hunk = path && line.match(/^@@ -\d+(?:,\d+)? \+(\d+)(?:,(\d+))? @@/)
  if (!hunk) continue
  const start = Number(hunk[1])
  const count = hunk[2] === undefined ? 1 : Number(hunk[2])
  const lines = changed.get(path) || new Set()
  for (let number = start; number < start + count; number++) lines.add(number)
  changed.set(path, lines)
}

if (changed.size === 0) {
  console.log('changed-admin-coverage: no changed admin source lines.')
  process.exit(0)
}

const report = JSON.parse(readFileSync(reportPath, 'utf8'))
let executable = 0
let covered = 0
for (const [absolutePath, entry] of Object.entries(report)) {
  const relative = absolutePath.replaceAll('\\', '/').replace(`${root.replaceAll('\\', '/')}/`, '')
  const changedLines = changed.get(relative)
  if (!changedLines) continue
  for (const [id, statement] of Object.entries(entry.statementMap || {})) {
    if (!changedLines.has(statement.start.line)) continue
    executable++
    if ((entry.s?.[id] || 0) > 0) covered++
  }
}

if (executable === 0) {
  console.log('changed-admin-coverage: changed admin source contains no executable statements.')
  process.exit(0)
}

const percentage = (covered / executable) * 100
console.log(`changed-admin-coverage: ${covered}/${executable} executable changed statements covered (${percentage.toFixed(2)}%, required ${threshold.toFixed(2)}%).`)
process.exit(percentage + 0.00001 >= threshold ? 0 : 1)
