# FW-S1-SUPPORT-REVIEW-2026-10

Review date: 2026-10-18 UTC (2026-10-17 in America/Toronto).
Tracking mirror: waaseyaa/framework#3206. Owner: current delivery task.
Base: `38bd59f386de7234de12c2a6171c6b6f2692cc69`.
Isolated worktree: `framework-worktrees/support-review-3206`, branch
`codex/support-review-3206`.

## Decision and evidence

The expired review was reproduced with `php bin/check-support-contract`: exit 1,
`support contract review_by date has expired`. This review checks the existing
platform choices, rather than extending their upstream lifetimes. The policy
revision retains contract version `s1-v1`, all platform constraints, exclusions,
lifecycle rules and certification statuses. S1 remains a candidate; consumer
certification under `S0-SHEG-02` remains pending. No beta declaration, runtime
upgrade, release, deployment or support expansion is included.

Official sources checked on the review date:

| Axis | Observed upstream evidence | Disposition |
| --- | --- | --- |
| PHP 8.5 | [PHP support table](https://www.php.net/supported-versions.php): active support ends 2027-12-31; security support ends 2029-12-31. | Retain `>=8.5.0 <8.6.0`; CI selects the 8.5 patch and records runtime evidence. |
| Composer | [Composer maintenance policy](https://getcomposer.org/download/): 2.10 is the current feature line; 2.10.3 is listed stable. Bug/security maintenance is relative to the next minor release. | Retain feature line 2.10 and the existing constraint. Do not invent a dated EOL. A new feature release triggers reassessment. |
| Node 24 | [Node release schedule](https://raw.githubusercontent.com/nodejs/Release/main/schedule.json): maintenance starts 2026-10-20; EOL remains 2028-04-30. | Retain Node 24 for Admin build/test through maintenance. No serving-runtime claim or Node 26 adoption. |
| SQLite | [Ubuntu Noble updates package](https://packages.ubuntu.com/noble-updates/sqlite3): 3.45.1-1ubuntu2.8, including the matching `libsqlite3-0` dependency. | Retain `>=3.40.0 <4.0.0` and Ubuntu 24.04 package servicing. The package listing is provenance, not a claim that every patch is safe or a consumer runtime was tested. |
| Ubuntu | [Ubuntu lifecycle](https://ubuntu.com/about/release-cycle): 24.04 standard security maintenance runs through May 2029. | Retain pinned `ubuntu-24.04`, x86-64. Do not follow a floating runner or claim an ESM entitlement. |
| Browsers | [Playwright browser contract](https://playwright.dev/docs/browsers): browser binaries are tied to the Playwright release. The repository locks 1.60.0 with Chromium and Firefox projects. | Retain the exact lock as revision authority; no branded-browser or WebKit/Safari expansion. This review is not new browser execution evidence. |

## Notice and next review

The Node maintenance notice window opened on 2026-07-22. The 2026-09-03
acknowledgement under #2862 was already late for that notice; its promised
2026-10-15 review was also missed. Preserve those dates. This completed review
on 2026-10-18 is before the 2026-10-20 transition, but does not retrospectively
satisfy either missed deadline. Its outcome is to retain the still-maintained
build/test line within the existing support boundary.

Set `last_reviewed` to the actual UTC review date and the next `review_by` to
2027-01-15, 89 days later and within the existing 93-day bound. The original
acknowledgement's `review_by` remains 2026-10-15; the checker recognizes the
completed review through `last_reviewed`. Do not change or bypass the checker.
Every tagged release still requires review. Reassess earlier if an upstream
support row changes, particularly Composer's undated next feature release.
No other recorded transition enters its 90-day notice window before January 15.

## Ownership and verification

Owned files: `support/s1-v1.json`, the lifecycle spec, this record and the
`3206.s1-support-review.changed.md` release fragment. No production, dependency,
CI or checker code changes are planned. The recorded checker failure above is
the expired-review discriminator. Existing gate fixtures cover completed
acknowledgement reviews, stale/missing acknowledgements, terminal transitions
and parity refusals; retain and run those tests.

Qualification: candidate-local Composer install, support checker, both support
architecture test files, changelog and whitespace checks, required hooks and
governed preflight. Record exact candidate results in the GitHub mirror after
commit. Independent non-author review is required before integration because
the contract controls a mandatory safeguard. Prior delivery-policy review is
separate evidence and cannot stand in for this review.

After support integration, resume the already-reviewed Framework and Studio
delivery branches. A green local gate is not hosted qualification, consumer
certification or release approval.

At candidate `59ba4fa6ccaee47235a77a30a183007080c2d466`, the direct support
checker passes and changelog/whitespace checks pass. On native Windows with
PHP 8.5.5, the two support test files ran 62 tests and 669 assertions in 27.737
seconds: 59 passed and three failed. The failures are absolute Windows
`--contract` path handling and two POSIX executable-bit assertions. All three
fail identically on base `38bd59f386de7234de12c2a6171c6b6f2692cc69` using
the same worktree, runtime and unchanged dependency lock (3 tests, 18 assertions,
0.880 seconds); the candidate was restored afterward. These are recorded host
limitations, not a green Linux suite or policy regressions. No checker or test
behavior is changed to suppress them. The owning hosted S1 check remains required.

The first independent review found one P3 evidence-wording error: the record
had conflated the manual expired-review discriminator with fixture coverage.
This follow-up corrects that claim and records the base comparison. Policy
bytes remain unchanged; the repaired candidate needs the reviewer's delta verdict.
