# FW-SQLITE-CONTENTION-01: writer triage and HTTP window accounting

Issue #3183. Base `10bd819fc692ac031abf5598301c311c6660d664`.
Owned isolated worktree: sqlite-contention-3183, branch codex/3183-sqlite-contention.
Candidate-local lockfile installation, no donor vendor or bootstrap override.

## Evidence and scope

Read-only production log inspection confirms the retained 19:19:19 trace ends
at DBALInsert, without the caller or table. The exact production writer remains
unconfirmed. No production instrumentation, traffic burst or data mutation.

Two-connection deterministic gateway probes on main and installed alpha.303
identify INSERT into privileged_read_ledger as the synthetic failing statement.
Both audit-provider and kernel-fallback ledgers exhibit waitable contention at
the configured 5000 ms busy timeout and non-waitable retained cursor/transaction
snapshot upgrades. Releasing the snapshot/lock allows reserve and finalize,
retaining two durable rows. These constructed states do not establish incident
attribution and do not justify blind retries.

Eight concurrent real core kernel boots per cohort succeed, preserving 432
expected audit rows each. This uses the #3182 synthetic 31-definition fixture,
not authenticated FETDER media requests or a production FPM topology.

The HTTP DatabaseRateLimiter has a separate deterministic accounting defect:
two connections reading the same prior count both write the same increment;
concurrent expired-window resets overwrite each other; concurrent first inserts
produce UniqueConstraintViolationException. Two successful attempts may consume
only one count, or the second request may throw. The exact statements and
interleavings are reproduced on main and installed alpha.303. This slice repairs
that confirmed foundation-owned defect, not the unattributed production lock.

## Design and ownership

Own foundation's HTTP DatabaseRateLimiter, discriminating tests, infrastructure
spec, changelog and generated test-only rosters. Preserve fixed-window behavior,
including counting denied attempts. Conditional updates match key, prior count
and prior start. A lost update or typed first-insert unique collision re-reads
the current row within a bounded operation. Never retry SQLite lock errors or
discard an outer transaction/cursor; no busy-timeout/durability changes.
No auth-package limiter, gateway ledger, private media or access-policy changes.

Use maintained Doctrine exception types and existing database update primitives.
Symfony RateLimiter requires its storage/locking composition and has different
token/denied-attempt accounting; this slice retains the existing public fixed
window/table contract rather than replacing the limiter. The conditional update
is owning domain persistence policy, not a generic retry/cache/lock mechanism.

## Audit reconciliation

August SQLite inventory's waitable writer/non-waitable snapshot distinction and
portable Doctrine authority remain authoritative. Its string-only audit retry
matcher concern is independent and is not widened here. Current coverage index
does not assess database-legacy/entity-storage/audit as whole packages; foundation
has a route/lifecycle audit in progress. Completed groups and admin-surface
contracts remain unchanged. ADR-007 retains database-legacy as supported.
No package is marked converged. #2275/#2446 are historical failure mechanisms,
not attribution of this incident.

## Qualification contract

First capture red tests for first insert, active increment and expired reset
using real connections and deterministic pre-write interleaving. Prove final
counts/decisions, denied counting, freshness, and fail-closed snapshot errors.
Run existing limiter tests and focused middleware tests; default preflight and
independent immutable-candidate review. Native Windows broad-suite policy stays
in force. Reviewed batches land by normal fast-forward main push without a PR;
observe main feedback. Full exact-SHA release qualification remains separate.

Raw synthetic probes/results are retained in C:/dev/waaseyaa/3183-evidence.
Remaining #3183 acceptance: identify actual failing writer/cursor in authenticated
media traffic and qualify a real concurrent request run with private headers,
authorization, durable auditing and rate accounting. No release/deployment here.

## Local candidate evidence, 2026-10-03

Before repair, all three deterministic cases discriminated: first insert threw
UniqueConstraintViolationException; concurrent active increments admitted an
extra request; expiry reset lost an attempt. After repair, limiter, middleware
and real two-connection tests pass 20 tests / 66 assertions, including fail-closed
retained-snapshot refusal (one attempted write) and bounded 32-way starvation.
Focused PHPStan on the changed source reports no errors.

Real loopback HTTP probe:
`tests/Fixtures/Audits/Foundation/FW-SQLITE-CONTENTION-01-http-window.php`.
Eight independent PHP servers share one fixture database. Each cohort runs
80 requests for a fresh key and 80 for an expired key, ten bursts of eight.
This uses real RateLimitMiddleware and DatabaseRateLimiter with a public fixture
access label, not FETDER's authentication, media bytes or full HttpKernel.
Candidate: each mode returns exactly 3 HTTP 200 and 77 HTTP 429, no HTTP 500,
and stores all 80 attempts. Allowed responses retain private/no-store headers.
Main baseline: fresh mode returns 17 HTTP 200, 56 HTTP 429, 7 HTTP 500 and stores
only 13 counts; expired mode returns 24 HTTP 200, 56 HTTP 429 and stores 14.
These sampled baseline counts are not deterministic guarantees; the regression
interleavings prove the causes. The candidate also passes their exact decisions.
All test-owned servers terminate; no production workers/settings are changed.

Main/candidate PHP 8.5.5, DBAL 4.4.3 and identical Composer lock SHA-256
`c879487007897c66be2f8eb6b412a40da14319c42ca664e8802843563b22ef03`.
Published alpha.303 has independently resolved DBAL 4.5.0, so its results are
distribution baseline evidence, not isolated source or FETDER lock identity.

Symfony fit was verified against its supported 8.0 FixedWindowLimiter source
and official rate-limiter storage/lock documentation. Its refused consumption
returns before adding hits, unlike the existing denied-attempt counting contract:
https://github.com/symfony/rate-limiter/blob/8.0/Policy/FixedWindowLimiter.php
https://symfony.com/doc/current/rate_limiter.html
Replacing existing persistence/denied counting is a separate foundation-owner
convergence decision; revisit this compatibility gap when that contract or the
limiter's storage topology changes. This repair adds no dependency or generic
framework mechanism and retains portable conditional-update primitives.

Immutable review, generated test rosters and default preflight follow. Actual
production locked writer, authenticated media concurrence and live PostgreSQL/
MySQL qualification remain open. Neither synthetic race nor sampled HTTP error
is claimed as identification of the 19:19:19 production writer.

Published baseline: fresh window returns 10 HTTP 200, 63 HTTP 429 and seven
HTTP 500, storing 16 counts; expired reset returns 12 HTTP 200 and 68 HTTP 429,
storing 17. All fourteen baseline HTTP 500s have unique-key exception evidence
in synthetic server logs; none establishes the production database-locked writer.

Retained driver `FW-SQLITE-CONTENTION-01-http-window-run.py` sits beside the
HTTP fixture. Run with ARTIFACT_ROOT, `candidate` (strict assertions) or `baseline`
and optional OUTPUT_JSON; each artifact supplies its own installed dependencies.
The driver retains temporary synthetic databases/logs but stops every owned
server. It runs on Windows and POSIX with the configured php executable.

The native coordinator's Windows drive-path rejection, recorded in #3182,
remains a tooling limitation. This worktree is isolated and its owner/base/path
are recorded here; no lease bypass or other worktree modification.

Independent review requested two compatibility repairs: expired resets retain
the existing allowed result for zero limits, and unique collisions are retried
only through a direct DBAL connection known to be outside a transaction.
Opaque adapters and active caller-owned transactions preserve the original
exception. Both regression controls failed before repair; an active-transaction
control also verifies refusal. No SQLite lock exception is caught or retried.
