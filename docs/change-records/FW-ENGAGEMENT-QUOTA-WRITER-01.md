# FW-ENGAGEMENT-QUOTA-WRITER-01

Status: released in alpha.310 and qualified in an installed consumer, 15 November 2026.

The S1 fixed-window quota transaction fetched its state before acquiring SQLite's
writer. An unrelated connection could commit after that read snapshot, making
promotion to a writer fail immediately with SQLITE_BUSY. Per-actor file locks
coordinate the same quota key but cannot exclude other canonical-database writers.
The public service translated this into retryable quota-storage 503 responses.

The dedicated managed quota transaction now reserves SQLite's writer with a
no-op update to the migration-owned quota table before fetching window state.
This also works when the key has no row. It changes neither expiry nor the
quota budget, and retains Symfony FixedWindowLimiter, external nonblocking flock,
canonical storage, managed commit/rollback and the separate engagement mutation
transaction. No schema change, caller-owned transaction, audit bypass, additional
lock service, arbitrary retry or dependency change is introduced.

The owning engagement quota contract remains unchanged: this fixes the existing
short committed quota transaction before the later engagement writer. Regression
qualification must exercise an independent connection committing after the old
read, fresh and existing keys, writer-first exclusion, and quota boundaries.

The deterministic two-connection regression fails against the unchanged parent
with the exact quota-storage exception and SQLite code 5. With the correction,
78 engagement tests / 491 assertions pass on PHP 8.5.4. The regression covers new
and existing keys, both quota windows, writer ownership during fetch, unrelated
writer success after commit, exact admitted counts, injected failure rollback,
closed transactions and subsequent admission through a new limiter instance.

Evidence: `evidence/engagement-quota-writer/old-regression.txt` and
`evidence/engagement-quota-writer/fixed-tests.txt`. Independent review approved the immutable runtime/test patch with no blocking
findings, independently repeating 78 tests / 491 assertions. All 43 default
preflight gates passed. The exact mirrored installed consumer passed 12
engagement HTTP/kernel tests / 122 assertions. Published consumer adoption
and production qualification are recorded below. There is no production claim from these tests.

## Released and live qualification

The governed alpha.310 cut passed all five gates at source
`09aec65694577a44925bade734f042ebca20f83b`; its split/publication workflow also
passed, including every-package installability. The corrected limiter file
SHA-256 is `08a6e9d94e16d18ffe603cc928cd619a10aeacef2b79b7322673e5530c113980`.

The actual consumer resolved its 43-package development and 42-package runtime
closures from normal Packagist metadata, without package-repository overrides.
All references and installed file hashes matched the checksummed producer
provenance; no third-party versions or source references changed. Its complete
suite passed 379 tests / 6,382 assertions with zero failures/errors/warnings and
one expected host-specific skip. Fresh no-dev browser checks passed the affected
comment/reply/edit/removal and like/unlike journeys; a fixture-specific large-page
setup skip remains explicitly separate from the passing pagination kernel tests.

The same native four-client probe that failed against the prior published
limiter passed before and after the actual traffic switch: each run admitted six
serialized desired-state mutations alongside 18 public reads, all HTTP 200, bound
to the corrected installed limiter bytes. Authenticated live UI likes/unlikes,
comments and replies, anonymous author attribution and author cleanup passed.
Application-owned records retain the exact consumer lock, candidate identities,
independent reviews and production receipts. This closes this bounded quota race;
it does not claim that genuine storage outages or prolonged writer contention
cannot still produce retryable refusals.
