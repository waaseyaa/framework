import { appendFile, mkdir, readFile, writeFile } from 'node:fs/promises'
import { dirname, resolve } from 'node:path'
import { pathToFileURL } from 'node:url'

const failureStatuses = new Set(['failed', 'timedOut', 'interrupted'])

export function summarizePlaywrightReport(report) {
  const summary = {
    total: 0,
    passed: 0,
    flaky: 0,
    failed: 0,
    skipped: 0,
    firstAttemptFailures: 0,
    recoveredRetries: 0,
  }

  const visit = (suite) => {
    for (const spec of suite.specs ?? []) {
      for (const test of spec.tests ?? []) {
        summary.total++
        const results = test.results ?? []
        const firstStatus = results[0]?.status
        const finalStatus = results.at(-1)?.status
        const firstFailed = failureStatuses.has(firstStatus)
        const recovered = firstFailed && finalStatus === 'passed'

        if (firstFailed) summary.firstAttemptFailures++
        if (recovered) summary.recoveredRetries++

        if (test.status === 'skipped' || finalStatus === 'skipped') summary.skipped++
        else if (test.status === 'unexpected' || failureStatuses.has(finalStatus)) summary.failed++
        else if (test.status === 'flaky' || recovered) summary.flaky++
        else summary.passed++
      }
    }
    for (const child of suite.suites ?? []) visit(child)
  }

  for (const suite of report.suites ?? []) visit(suite)
  return summary
}

export function formatSummary(summary) {
  return [
    '### Playwright retry evidence',
    '',
    '| Total | Passed | Flaky | Failed | Skipped | First-attempt failures | Recovered retries |',
    '| ---: | ---: | ---: | ---: | ---: | ---: | ---: |',
    `| ${summary.total} | ${summary.passed} | ${summary.flaky} | ${summary.failed} | ${summary.skipped} | ${summary.firstAttemptFailures} | ${summary.recoveredRetries} |`,
    '',
  ].join('\n')
}

async function main() {
  const input = resolve(process.argv[2] ?? 'test-results/results.json')
  const output = resolve(process.argv[3] ?? 'test-results/flake-summary.json')
  const report = JSON.parse(await readFile(input, 'utf8'))
  const summary = summarizePlaywrightReport(report)

  await mkdir(dirname(output), { recursive: true })
  await writeFile(output, `${JSON.stringify(summary, null, 2)}\n`)
  const markdown = formatSummary(summary)
  process.stdout.write(markdown)
  if (process.env.GITHUB_STEP_SUMMARY) {
    await appendFile(process.env.GITHUB_STEP_SUMMARY, markdown)
  }
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
  await main()
}
