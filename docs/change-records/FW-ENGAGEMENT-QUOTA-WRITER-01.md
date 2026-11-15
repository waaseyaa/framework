# FW-ENGAGEMENT-QUOTA-WRITER-01

Status: candidate implementation, 14 November 2026.

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
`evidence/engagement-quota-writer/fixed-tests.txt`. Independent review and published
consumer adoption remain pending. There is no production claim from these tests.
