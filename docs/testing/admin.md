# Admin test policy

## Runtime

`.nvmrc` is the single source of truth for the repository Node.js major. Local
development, admin CI, dependency audit, generated-dist checks, and release
workflows all use Node 24 LTS. `packages/admin/package.json` rejects other
majors so a local runtime cannot silently differ from CI. Review the pinned LTS
major before it enters maintenance and upgrade it in one change.

## Coverage

Vitest publishes V8/Istanbul output from `packages/admin/coverage/`. Global
coverage is measured and recorded, while changed executable statements must
remain at least 80 percent covered. The changed-code ratchet is the merge gate;
global percentages are evidence and must not be presented as perfect coverage.

Nuxt 4.5 corrected source-map accounting for 16 untested page components that
Nuxt 4.4 omitted from the V8 denominator. The honest baseline is therefore
66.31 percent lines, 64.42 percent statements, 64.86 percent functions, and
62.16 percent branches, with conservative integer floors of 66/64/64/62. The
covered counts stayed effectively flat across the upgrade; the denominator
grew from 3,214 to 3,772 statements. Raising these global floors requires
tests, while the 80 percent changed-statement ratchet prevents new code from
expanding the debt.

## Browser matrix and retries

The required production-shaped smoke suite runs Chromium and Firefox. WebKit
is not part of the support contract until application analytics, client needs,
or a documented compatibility commitment justifies its operating cost.

CI may retry a browser test twice for runner and browser-process resilience,
but a retry does not erase the first failure. Playwright emits HTML and JSON
reports; `scripts/summarize-playwright-flakes.mjs` records first-attempt failures
and recovered retries in `test-results/flake-summary.json` and the
GitHub job summary. The complete `test-results/` directory is retained with the
browser report. A terminal failure remains blocking. Repeated recovered retries
must be repaired or quarantined with an owner and issue, never accepted as a
new baseline.
