# 008 — Ledger checksum backfill for pre-WP09 rows

**Status:** Superseded for S1 certification (2026-08-12)
**Mission:** #529 (Schema Evolution v2.0), WP09
**Spec context:** `docs/specs/schema-evolution-v2.md` §6.3

## Context

WP09 added two columns to `waaseyaa_migrations`:

- `checksum` — SHA-256 over the canonical SchemaDiff JSON (the source intent).
- `diff_hash` — SHA-256 over the canonical compiled-plan JSON (the SQL emitted).

Both are nullable VARCHAR(64). V2 migrations populate canonical intent and
compiled-plan hashes. New legacy applies now populate an exact executable-source
checksum plus a domain-separated procedural-plan hash; historical nulls remain
unchanged because current source cannot prove what ran in the past.

Existing installs have ledger rows that pre-date WP09. Those rows have null `checksum` and null `diff_hash` — even for what *would* now be v2 migrations, no fingerprint was recorded at the time of apply. Verify mode (WP10) needs to know how to interpret these.

## Options considered

### A. Backfill: re-canonicalize from current source

Walk every ledger row, find the migration source by id, compute its current `MigrationPlan::checksum()`, write it back.

**Problems:**

- Re-canonicalizing today's source is not the same as canonicalizing what was applied historically — if the source has changed since apply, the backfill records a fiction. Verify mode would then *miss* drift it should be catching.
- Migration sources can disappear (uninstalled packages, deleted local migrations). A backfill that silently skips missing sources leaves a partial audit trail that looks complete.
- The operation is slow for installs with thousands of rows and is irreversible without a snapshot.

### B. Null-tolerate: leave the rows null and treat null as "unknown"

Verify mode reads a null `checksum` as `VerifyResult::Unknown` and logs a notice rather than failing. The audit trail is honest: "this row predates WP09; we cannot verify its intent."

**Trade-offs:**

- Verify mode never raises a hash mismatch on pre-WP09 rows. That is intentional — there is nothing to compare against.
- Operators who want a complete audit trail can re-author the migration with a fresh `migration_id` (after Q1's stable-key rule) and re-apply on a clean install.

## Decision

**Historical decision:** null remains the canonical sentinel for "pre-WP09
apply, fingerprint not recorded." S1 retains that honest representation but no
longer treats it as successful verification: `unknown` is fail-closed until an
operator supplies separately reviewed historical evidence or rebuilds from a
fully verifiable source set.

A backfill CLI is *not* shipped in v1. Most installs should never run one. If a future engagement genuinely needs a complete audit trail for legacy rows, the recipe is:

1. Snapshot the database.
2. Manually compute the checksum from the migration source as it was at the time of original apply (likely from git history at the deploy commit).
3. UPDATE the ledger row directly.

This is operator-driven, auditable, and refuses to lie about historical state.

## Consequences

- The `checksum` column is nullable forever — removing it would break verify mode's tri-state (`Match` / `Mismatch` / `Unknown` / `Missing`).
- Documentation must call out that null `checksum` is an *intentional* sentinel, not a bug. Operators reading the ledger should not "tidy up" by running an UPDATE that fills in nulls based on current source — that recreates option A's problems silently.
- Verify mode reports every `Unknown` row and exits non-zero. The count remains
  visible so operators can scope the unverifiable historical surface.

## References

- Spec §6.3: "Backfill: ADR — sentinel value, nullable columns, or one-time backfill script; verify mode defines behavior for 'legacy unknown.'"
- WP09 work package, T053.
- `packages/foundation/src/Migration/VerifyResult.php` — the `Unknown` case formalises this decision.
