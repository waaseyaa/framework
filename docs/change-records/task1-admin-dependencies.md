# FW-DEPENDENCY-MAINTENANCE-20261030: Admin candidate

Current Git-toolchain follow-up:
[FW-A35-GIT-TOOLCHAIN-01](FW-A35-GIT-TOOLCHAIN-01.md) records fresh evidence that
DevTools 3.4.4 fits Nuxt 4.5.2's owner range and removes the affected wrappers.
The earlier candidate/disposition below is retained as historical evidence;
it must not be read as a fresh assertion that no supported owner fix exists.

Observed October 30, 2026 against base
`624c70328e07e9c41f362305370ebf07cd0909c8`.
Owner: Complete dependency and delivery reconciliation,
chat `01a18e88-a4e3-7aa0-8b8a-354d5f57598d`.

## Bounded repair and acceptance

Upgrade only compatible transitive `launch-editor` 2.14.1 to 2.14.2 and
`shell-quote` 1.10.0 to 1.12.0. Actual ownership is Nuxt 4.5.2, DevTools 3.4.1,
launch-editor (`^2.14.1`), shell-quote (`^1.8.4`, then `^1.10.0`). The existing
owner ranges admit both patches. No override, root dependency, Nuxt major,
test-environment major or application behavior is changed. npm 11.8 removed
unrelated optional-package `libc` metadata during resolution; that incidental
change was excluded from the candidate. All other lock entries remain equal.

This replaces the advisory coverage proposed by PRs #3190 and #3229, without
closing or modifying them. Relevant advisories are shell-quote
[GHSA-pqg4-j6r4-53mv](https://github.com/ljharb/shell-quote/security/advisories/GHSA-pqg4-j6r4-53mv)
and launch-editor GHSA-r825-w8mm-5pxw, GHSA-587h-7wmj-h5pm,
GHSA-698v-4r3x-3vcv. The editor and quoting execution boundary belongs to the
development server/toolchain. The static production Admin is served by PHP;
this does not establish that attacker input cannot reach development tooling.

Verification plan: compare exact lock entries and parent ranges, exercise
shell-quote's line-terminator refusal without executing its output, run focused
Admin tests and the canonical `bin/build-admin-dist` operation. That operation
must independently generate two identical trees and satisfy the declared source
markers, freshness and acceptance manifest. Use pinned Node major 24, with a
verified portable Node 24.22.2/npm 11.19.1 toolchain outside the checkout.
Independent review binds the final immutable commit; full PR CI owns hosted
qualification. No competing full local suite is authorized. A build/refusal
failure requires focused diagnosis, not an unreviewed signature refresh.

## Remaining alert dispositions

The fresh GitHub inventory has 50 alerts: 4 PHP and 46 npm, with 18 distinct
package names in total, 16 npm names. The separate PHP candidate also fixes an
ExpressionLanguage advisory discovered by Composer audit. This Admin repair
addresses four GitHub alerts; it does not claim to clear the backlog.

| Package | Exposure and owner | Disposition and next action |
| --- | --- | --- |
| simple-git 3.36.0 | Nuxt -> DevTools 3.4.1, development Git execution | Critical GHSA-x6jw-m9v5-85vh requires 4.0.1; two high advisories require 4.0.0. Current DevTools declares `^3.36.0`, with no patched 3.x release. Framework maintainer must select an upstream-supported DevTools/Nuxt owner release or explicitly review removal/major migration. Keep alerts open. |
| @simple-git/argv-parser 1.1.1 | simple-git `^1.1.0`, Git environment safety | Critical GHSA-v5rq-49vh-5v5c requires 2.0.1. Same owner/design decision; do not force a parser major into simple-git 3. |
| undici 8.10.0 | Nuxt build/dev HTTP client | 19 alerts; current patched floor 8.12.2. PR #3173's 8.11.2 is insufficient. Qualify a current compatible owner resolution, including #3233's same-major Nuxt/Nitro update, in the next bounded candidate. |
| nuxt / @nuxt/nitro-server 4.5.2 | static generation and development server | PR #3233 targets 4.6.2 and addresses the current Nuxt/Nitro cohort; verify exact Node engine and resulting dependency closure, then canonical rebuild. It does not resolve DevTools' incompatible simple-git chain. |
| happy-dom 20.11.12 | direct test-only dependency | Current fix 21.0.4 is a major. PR #3187's 20.14.5 remains affected. Both declared test-utils 4.0.3 and locked 4.1.0 allow `>=20.0.11`, but a peer range is not test qualification. Review and qualify the test-environment major separately. |
| @vue/server-renderer 3.5.41 | Nuxt prerender SSR; Vue is also shipped client runtime | PR #3193 pairs Vue/renderer; verify lock >=3.5.42 and package alignment plus generated consumer bytes. Do not classify Vue as exclusively build tooling. |
| seroval 1.6.2 | @nuxt/vite-builder; client-bundle provenance unresolved | High fix >=1.6.3, PR #3194 targets 1.6.8. Qualify compatible lock/build and inspect output reachability; no assertion of absent browser exposure. |
| brace-expansion 2.1.4 / 5.0.9 | glob/minimatch build/test tooling | Current floors 2.1.7 / 5.0.12. Verify both copies and PR #3177's current exact versions, then qualify the resulting lock. |
| postcss-selector-parser 7.1.4 | CSS build processing | PR #3191 targets fixed 7.1.6; qualify with canonical distribution rebuild. |
| serialize-javascript 7.0.7 | build serialization | Fix >=7.1.3; no matching open PR. Framework maintenance owner should prepare a compatible lock candidate and verify emitted code behavior. |
| @vitest/mocker 4.1.11 | Vitest test mocking/server tooling | Fix 4.1.12 for two medium alerts; qualify through the matching Vitest owner cohort, no independent mismatched override. |
| node-forge 1.4.0 | DevTools -> listhen, development TLS certificate tooling | High GHSA-86w9-cpqp-85rv lists no patch. npm has 1.4.2 but that alone is not remediation evidence. Obtain upstream fix/range confirmation or a supported owner removal; retain open. |
| deepmerge 4.3.1 | Rollup node-resolve build tooling | Low GHSA-gcqx-w48m-w2h4 has no listed patch. Inspect the owner's affected merge use and upstream resolution; retain open pending evidence. |

Urgent next slice: prioritize supported fixes for Undici, Nuxt/Nitro, Vue/SSR,
seroval and both brace-expansion copies while retaining the two critical Git
toolchain gaps. Node-forge needs an upstream answer. Do not batch-merge old PRs,
dismiss findings by severity/exposure inference, or describe this bounded repair
as repository-wide dependency acceptance. The Framework maintainer retains the
unresolved items; this candidate does not authorize a major migration.

## Evidence custody and publication limit

Detailed live alerts, PR overlap, current registry ownership, toolchain archive
checksum, build/test logs and independent reviews are retained in
`C:/dev/waaseyaa/task1-evidence/20261030/`. Static output identity travels in
the committed dist manifest after canonical acceptance. Candidate qualification,
published-package qualification and application adoption are separate claims.
Stop before merge, tagging, release, package publication and deployment.

## Candidate qualification receipt

Canonical build passed using Node 24.22.2/npm 11.19.1. Both clean builds
converged, all source markers passed, and the final freshness and manifest
checks passed. Published tree digest:
`0fdba12870680420f4e7d089b9475b6c0ffc77128d148a78705cd8d676c78912`
(99 files / 597,692 bytes). The accepted change inventory has one added
build-metadata path, 19 modified HTML/metadata paths and one obsolete metadata
path removed. No compiled JS/CSS chunk changes occur in this patch.

The installed shell-quote probe refuses all four line terminators after a
comment token and round-trips normal quoted arguments. No shell command is
executed by that probe. Focused distribution acceptance/content/guard tests
passed 32 tests / 401 assertions. Vitest tooling and public-auth-path smoke
checks passed three files / six tests on the same Node runtime.

The resulting npm locked audit has no shell-quote or launch-editor advisory
entry. It still reports 21 vulnerable dependency/owner records, including two
critical entries; npm's aggregate counts are not GitHub's advisory-alert counts.
Keep the remaining-disposition table above. Local preflight and exact-commit
hosted CI results are recorded in the PR and external task evidence; this
receipt does not label a pending or failed gate as passed.

## Admin integration runner correction

Separate Admin SPA workflow run [41132239620](https://github.com/waaseyaa/framework/actions/runs/41132239620),
job `124326055593` (`admin/integration`), selected Ubuntu 26.04 through
`ubuntu-latest`. Playwright 1.60.0 failed its Chromium install before the test
step with `Playwright does not support chromium on ubuntu26.04-x64`; the retry
summary then lacked `results.json` because tests never started. Pin only this
integration job to `ubuntu-24.04`, matching the existing Framework CI runner
and `support/s1-v1.json` framework OS point. Keep the locked Playwright version
and browser revisions unchanged.

The workflow edit changes no Admin source, dependency lock, or D6 signature
input. On this candidate, `bin/check-admin-dist-fresh` still reports source
signature `400f0b0087fa4d6136134a86387071f14bc797af4b5864723b9e24e726de2c55`,
and `bin/admin-dist-acceptance verify` confirms the committed dist matches its
manifest. No distribution rebuild or signature rewrite is indicated. The
edited workflow inventory is regenerated from source. The next exact hosted
Admin workflow run owns confirmation on Ubuntu 24.04; this correction does not
claim broader runner or browser support.
