# #2985 audit lane — audit-ledger transactional joining (#2819)

**Anchor:** #2985 · **Scoped to:** open issue #2819 (p1, `status:needs-design`, `area:security`, beta-blocker)
**Pinned commit:** `870c41c01058fb92cb6992cbbfd315f187f50b35`
**Branch / worktree:** `audit/2985-audit-ledger-joining` — `fw-2985-audit-ledger`
**Mode:** read-only. No runtime file modified; no repair implemented.

## Method and evidence standard

Four research lanes traced the surface; every load-bearing claim was re-verified
against source by the lane owner. CONFIRMED = read in the code; HYPOTHESIS =
inference. **All findings are CODE EVIDENCE — nothing was executed.**

Throughout, two things are kept strictly apart, per the anchor owner's
instruction:

- **(A) Atomic audit participation** — the audit record commits in the *same*
  transaction as the domain mutation, or both fail. This is what #2819 asks for.
- **(B) After-commit side effect** — the record is written by a listener after
  the mutation already committed; a failure leaves the mutation durable and the
  record missing. This is the territory of T-7 and **#2999**, and is *not*
  re-litigated here.

### Coverage matrix

| Area | Reviewed | Depth |
|---|---|---|
| All audit write paths (15 enumerated) | Yes | Source read + registration census |
| `StrictAuditLedgerInterface` / `DatabaseStrictAuditLedger` | Yes | Full read incl. reserve/finalize bodies |
| `StrictPrivilegedReadLedgerInterface` + batch variant | Yes | Method-set comparison |
| `AuditEventKind`, `AuditEventDescriptor`, `PrivilegedReadDescriptor` | Yes | Field-by-field against #2819 |
| Attribute-schema validation, secret prevention | Yes | Establishing absence, paths named |
| Dangling-reservation detection | Yes | Reader census of `strict_audit_ledger` |
| `docs/specs/ocap-audit-log.md`, `mcp-endpoint.md`, `entity-field-read-boundary.md` | Yes | Contract quotes |
| Integrity / append-only / retention / tenancy internals | Yes | Full read of `Integrity/`, `Query/`, `ReadModel/`, `Schedule/`, `Rekey/` |
| Executed proof | **No** | Out of scope |

## The documented contract

`docs/specs/ocap-audit-log.md` settles the durability posture unambiguously:

> ":523 — `AuditWriterInterface` is contractually best-effort — `record()` MUST
> swallow every exception and MUST NOT throw (FR-005 / NFR-001)."
> ":542 — **The guarantee is pre-durability, not atomicity.**"

`StrictAuditLedgerInterface` states its own limit in the same terms: "`reserve()`
must be durable **before** the caller performs the side effect. That yields: *no
side effect can occur without a durable record of the attempt.* It does **not**
yield atomicity between the side effect and `finalize()`."

**The documentation agrees with #2819 on every clause.** #2819 accurately
restates a documented boundary rather than reporting a regression — consistent
with its `status:needs-design` label. Correspondingly, **no spec requires that a
security-authority change be recorded loss-proof**; the nearest statement
(`ocap-audit-log.md:82`, "must be captured immutably") governs what happens to a
record *once written*, not whether it gets written. #2819 is asking for a new
guarantee, not compliance with an existing one.

## L-1 — CONFIRMED: no path today satisfies (A)

Fifteen audit write paths were enumerated and their registration verified. Every
one is category (B) or an explicitly-separate commit:

- Ten `AuditWriterInterface` listeners fire on `POST_SAVE`/`POST_DELETE`, which
  `EntityRepository` dispatches strictly **after** commit — the transaction lane
  established this as an enforced invariant, and it is definitionally (B).
- `AuditEventWriter` swallows every exception, logs, notifies an observer, and
  attempts a degraded-marker row. It never throws.
- `DatabaseStrictAuditLedger::reserve()` **deliberately does not join a caller
  transaction**, and says so: "A reservation that committed together with the
  mutation would vanish with it." Its body contains no `transaction()` call.
- `DatabaseStrictAuditLedger::finalize()` opens its **own** transaction, named
  `'strict-audit-finalize'`, wrapping only the finalize row.
- `DatabaseStrictPrivilegedReadLedger` follows the same pattern with a
  SQLite-contention retry loop.
- `EntityWriteAuditListener` writes a separate JSONL file, also post-commit.

Taking a `DatabaseInterface` is **not** transaction joining: each writer resolves
the connection independently at construction and manages its own boundary. So the
answer to #2819's premise is confirmed — **zero paths commit an audit record
atomically with the mutation it describes.**

## L-2 — CONFIRMED: of the four documented obstacles, one is obsolete and one is a tradeoff #2819 explicitly takes

`docs/specs/mcp-endpoint.md:946-951` is the authoritative statement of why atomic
coupling "in the general case cannot be joined". Re-examined against HEAD:

| # | Documented obstacle | Status at `870c41c01` |
|---|---|---|
| 1 | "Tools commit internally — `IdempotencyStore::execute()` opens and commits its own transaction… The bridge holds no handle on that boundary." | **Still true.** Note this is the same `IdempotencyStore` carrying the T-9 defect now tracked as **#2999** — a repair there touches this boundary. |
| 2 | "`DBALTransaction` has no savepoint support, so a legitimate inner domain rollback under DBAL's default nesting would poison an enclosing audit write." | **FALSE at HEAD.** DBAL 4.4.3 nests with savepoints unconditionally (`Connection.php:1062-1064`, `:1152-1155`); disabling them throws. `TransactionCompletionCoordinator` (#2734, `4c0562470`) provides managed nesting that promotes inner callbacks to the parent and discards them on rollback. An inner domain rollback does **not** poison an enclosing write. |
| 3 | "Entity storage resolves through `ConnectionResolverInterface`, so a multi-connection deployment puts entity writes on a different connection from the audit log entirely." | **Still true as a possibility.** It bounds *where* atomicity is achievable rather than forbidding it: it does not apply to single-connection deployments. Whether multi-connection is a supported default was not established by this lane. |
| 4 | "Pre-durability and atomicity are mutually exclusive: a record committing *with* the mutation is by definition not durable *before* it." | **Logically sound, and not an obstacle to #2819.** It says you must choose which guarantee you want for a given record. The strict ledger chose pre-durability; #2819 explicitly chooses atomicity ("commit atomically or both fail"). The two can coexist as *different* record kinds — they cannot coexist for the *same* record. |

The dating is clean and worth recording. The same reasoning appears in
`StrictAuditLedgerInterface`'s docblock, written **2026-08-04** (`d19f09d5f`,
its only commit). The completion-stack fix landed **2026-09-01** — four weeks
later — and the interface has never been touched since. **The rationale for "not
reachable" predates the machinery that would make it reachable, and was never
revisited.**

Accurate summary for design work: **one obstacle is obsolete, one is a tradeoff
#2819 takes the other side of, and two remain** — and the two that remain scope
the achievable target (single-connection deployments where the mutation's
transaction boundary is reachable) rather than closing it off. This lane does not
propose a design.

## L-3 — CONFIRMED: the descriptor #2819 wants already exists, on the other ledger

`AuditEventDescriptor` takes 8 constructor parameters and validates two.
`PrivilegedReadDescriptor` takes 17 and **hard-fails on incomplete metadata**:
`'Privileged read descriptors require complete non-value metadata.'`

| #2819 requires | `AuditEventDescriptor` | `PrivilegedReadDescriptor` |
|---|---|---|
| tenant | **absent** | `tenantId`, plus `communityId` |
| actor / service identity | `accountUid: ?int` — accounts only | `actorId: int\|string\|null` + `actorSemantics` enum |
| correlation | **absent** (ad hoc inside `attributes`) | `correlationId: string`, required non-empty |
| subject | `subjectUri` + `entityTypeId`/`entityUuid` | `entityTypeId` + `entityId` |
| decision / reason | `outcome` validated; reason free-text | `reason: CapabilityReason` — typed enum |
| immutable before/after refs | **absent** (ad hoc in `attributes`) | `classificationGeneration`, `policyGeneration` |
| completeness enforcement | none | throws on any missing field |

The record shape #2819 specifies is therefore not a greenfield design — it is
implemented, validated and shipping one interface over, for privileged reads.
That reframes part of the work as lifting an existing pattern onto the general
audit path.

## L-4 — CONFIRMED: audit kinds are closed to applications

`AuditEventKind` is a native PHP backed enum with 29 cases in one framework file.
PHP enums cannot be reopened or subclassed, and a search of `packages/*/src` and
`packages/*/composer.json` for any registry, tag, attribute hook, or provider
mechanism returned nothing. All 27 uses outside `packages/audit/src` reference
existing cases only.

`AuditEventKindAmendmentTest` makes this governance as well as language: it pins
`assertCount(29, AuditEventKind::cases())` and asserts the original 14 backing
values still resolve, enforcing additive-only, framework-side amendment. The spec
agrees: "new cases MUST be additive only. Removal requires a deprecation period
and a major-version bump."

`PrivilegedReadKind` is closed the same way (2 cases).

**#2819's first requirement — applications declaring kinds without editing
framework enums — is unmet, by construction.**

## L-5 — CONFIRMED: no attribute schema, and no secret-material control

`AuditEventDescriptor::$attributes` is `array<string, mixed>`, unvalidated by the
constructor, and `AuditEventWriter` JSON-encodes it verbatim.
`packages/audit/src/Schema/` contains exactly one file, a *database-column
existence* checker — not a payload validator. There is no JSON-Schema, typed DTO,
or per-kind schema anywhere under `packages/audit/src`.

Consequently the fields #2819 requires (tenant, correlation, reason, before/after
refs) are today carried, when carried at all, as untyped keys inside `attributes`
by per-listener convention — e.g. `from_revision_id`/`to_revision_id` in
`PublishPointerAuditListener`, `correlation_id` in the MCP listeners.

No framework-level control prevents secret material entering a record. The only
mechanisms found are bespoke and local: a hardcoded field allowlist in one
bootstrap reader, and MCP redaction explicitly delegated to each tool's own
`argumentsForAudit()` — with the audit package performing no check that it
happened. Bounded to `packages/audit/src`.

## L-6 — CONFIRMED: the documented crash-window safety property has no implementation

Both the interface and `docs/specs/mcp-endpoint.md` describe a **dangling
reservation** — a `reserved` row with no `finalized` row — as the crash-window
state, state that it "MUST be treated as 'outcome unknown, side effect may have
happened'", warn it "must never trigger a blind retry or rollback", and the spec
even publishes the detection SQL.

**Nothing in the framework runs that query.** Production readers of
`strict_audit_ledger` are: the finalize-time receipt guard
(`DatabaseStrictAuditLedger:73`), an approval-evidence lookup (`:157`), the
append-only table list, the read-model field-level registry, and the deployer's
table catalogue. The audit CLI offers `CheckpointCommand`,
`MigrateCheckpointSignaturesCommand`, `PruneCommand`, `VerifyCommand` — none
queries for dangling reservations. No scheduled reconciliation exists. Bounded to
`packages/{audit,foundation,cli,mcp}/src` and `packages/*/src/Schedule/`.

So the safety property is documented, correct, and **unsurfaced**: an operator
would have to know to run the SQL by hand. This bears directly on #2819's
"retry/idempotency rules prevent duplicate or missing lifecycle records", and is
the one finding here that is actionable independently of the atomicity design.

## L-7 — CONFIRMED: one audit path can crash a committed request

`EntityWriteAuditListener` (the JSONL trail in `packages/entity`) contains **zero**
`try`/`catch`, and `EntityAuditLogger::append()` calls `file_put_contents` with no
guard. It is registered on the kernel dispatcher in `AbstractKernel::boot()`
before provider discovery — first among all `POST_SAVE` listeners.

This is category (B) and therefore belongs to **#2999**, not to #2819's atomicity
question. It is recorded here only because it is an *audit* path and would
otherwise look like an #2819 concern: a disk-full or permissions failure on the
audit file throws uncaught after the domain mutation has committed, skipping every
later `POST_SAVE` listener and surfacing as a 500 for a durable write.

`AuditWriteFailureObserver` is a deliberate extension seam with no first-party
implementation — its own docblock says a real implementation is "a downstream L6
concern and out of scope for this package". Absence of an implementer is by
design, not neglect.

## L-8 — CONFIRMED (favourable): retention pruning is chain-aware and cannot mask tampering

The sharpest risk in a hash-chained append-only log is that retention deletion
breaks verification, so a real tamper becomes indistinguishable from a legitimate
prune. That risk is closed here, deliberately:

- `audit:prune` deletes only **whole sealed checkpoint segments**, and only when
  every row in the segment predates the cutoff.
- It never alters `checkpoint_hash`, `segment_hash` or `prev_checkpoint_hash` —
  only `pruned=1` plus a keyed `prune_authorization` tag over the unchanged
  checkpoint hash, written in the same transaction as the row delete. The
  checkpoint-to-checkpoint chain link therefore survives.
- `AuditChainVerifier` special-cases a pruned segment: it still verifies the
  chain link and recomputes the checkpoint hash, and it requires either a valid
  detached `prune_authorization` or coverage by a verified succession anchor
  before accepting the absence of rows. An unauthorized raw `DELETE` of sealed
  rows is still caught as a `row_count` or `checkpoint_chain` failure.
- `PruneCommand` runs `AuditChainVerifier::verify()` as a **preflight and refuses
  to proceed on an already-broken chain** — verified verbatim: *"audit:prune
  refused because the sealed audit chain failed verification."* It will not tidy
  away evidence of prior tampering.
- The `AuditRetentionPruned` self-audit event is written **before** the deletion,
  landing above the horizon so it can never fall inside the deleted range.

Recorded as a design worth preserving: any #2819 repair that adds records to these
tables inherits this property and must not break it.

## L-9 — CONFIRMED: two integrity gaps, precisely bounded

**The unsealed tail carries no hashes.** Checkpoints seal on a schedule whose
default is `*/15 * * * *` (configurable via `schedule.audit_checkpoint_cron`).
Rows above the last sealed `segment_end_id` have no `row_hash`/`prev_hash` yet, so
raw-database tampering inside that window leaves no chain evidence.

Two honest qualifications: the append-only decorator still blocks `UPDATE`/`DELETE`
for any caller that goes through it, so this is a raw-DB-access concern rather than
an application-path one; and `verify()` **reports** the unsealed-row count in its
result rather than silently ignoring it, so the window is disclosed by the tooling
rather than hidden.

**Unkeyed custody is non-cryptographic.** If `AuditCheckpointCustody` has neither
keyring nor HMAC key, signature verification degrades to a 64-hex shape check, and
a raw-DB attacker could rewrite history with self-consistent recomputed hashes and
still verify as intact. `AuditServiceProvider` always constructs custody with at
least a legacy key derived from `ApplicationSecret`, so this is reachable only by
bypassing the provider. **CONFIRMED that the code path exists; HYPOTHESIS that no
production wiring reaches it** — this lane did not enumerate every construction
site.

The shipped export sink states its own limit: `FileCheckpointSink` writes NDJSON
carrying full integrity metadata but is "only as trustworthy as the host", and the
code says production operators MUST configure an external/WORM sink.

## L-10 — CONFIRMED: append-only is a decorator, not a database constraint

`AppendOnlyAuditDatabase` throws on `UPDATE`/`DELETE`/destructive DDL against
seven audit tables, including a token-normalising raw-SQL scan. Its own docblock
states it is "a decorator, not a database trigger" — by design, because the
sanctioned prune path must still delete.

No DB-level trigger, CHECK or permission enforces this; `AuditEventSchemaHandler`
only asserts column existence. Enforcement therefore depends on which binding a
caller resolves: code that takes the raw `DatabaseInterface` from the container can
mutate these tables freely. Four such bypasses exist and all are legitimate and
narrow — the checkpoint builder writing `row_hash`/`prev_hash`, genesis-signature
backfill, `PruneCommand`, and the legacy-signature migrator, each optimistic-locked
or transaction-scoped.

Recorded as a **convention boundary rather than a hard guarantee**, so a repair
adding a joined writer must go through the decorator or justify why not.

## L-11 — CONFIRMED: `audit_event` has no tenant dimension at all

This is an absence finding, not an inference from a column's presence. The
`audit_event` schema has no `tenant_id`/`community_id` column; `AuditQuery` exposes
no tenant parameter; `AuditEventQuery::applyFilters()` applies only account,
entity, subject, kind and time conditions; and `AuditEvent` has no tenant accessor.
`AuditQueryInterface` is bound as an unscoped container singleton.

So there is nothing to enforce on read — the concept does not exist in this table.
That is why #2819 lists tenant as a *required new field* rather than a bug.

Tenancy does exist on `PrivilegedReadDescriptor` and is written to
`privileged_read_ledger`, but that ledger has **no read interface at all** —
`StrictPrivilegedReadLedgerInterface` exposes only reserve/finalize — so no
cross-tenant read exposure is reachable through it either. This makes #2815 a
genuine dependency for #2819 rather than a parallel concern.

## L-12 — CONFIRMED (favourable): key rotation preserves historical verifiability

`AuditCheckpointSuccessionRekeyAdapter` never rewrites existing checkpoints or
their signatures. It appends a succession anchor — itself append-only and
hash-chained — committing to a digest over every existing checkpoint, the pruned
evidence digest, the terminal checkpoint identity, and the from/to master
versions, signed under the new key. It verifies the predecessor-keyed history
before anchoring, and refuses mixed-version history.

After rotation, old records remain verifiable, but the trust path shifts from
"re-verify each old signature with the old key" to "verify the anchor under the
active key, then trust the checkpoints it commits to." Old signatures are retained
as historical artifacts, never destroyed. A rollback path appends a
`succession-rollback` record under the same discipline.

## Corrected and disproved leads

- **The two "strict ledgers" are not duplication.** `StrictAuditLedgerInterface`
  (foundation) and `StrictPrivilegedReadLedgerInterface` (audit) have different
  method sets — three methods vs two, with a batch variant — and entirely disjoint
  DTOs. The layering is deliberate and documented: the foundation port exists
  because "its first consumer — the MCP write tier — must not require
  `waaseyaa/audit` at runtime". Modelled on, not duplicated from.
- **#2819's "ADR-002" citation does not resolve in this repository.** This repo's
  `docs/adr/002-notification-ownership.md` is about notification delivery
  ownership and has no bearing on audit or credentials. The citation most likely
  refers to a different repository's ADR-002. Flagged so a reader following it
  here does not land on the wrong document; not investigated further, since the
  other repository is out of scope.
- **The spec is not stale on best-effort.** Unlike the cache lane, where a spec
  claimed a fix that was not live, `ocap-audit-log.md` accurately describes what
  the code does. The only stale text found is obstacle #2 in L-2.

## Existing issue mapping

| Issue | State | Relation |
|---|---|---|
| **#2819** | open, p1, `status:needs-design`, beta-blocker | This lane's subject. Premise CONFIRMED (L-1); requirements confirmed unmet (L-3, L-4, L-5); feasibility improved (L-2); one requirement actionable now (L-6). |
| **#2999** | open, p1 | Owns everything in category (B), including L-7. The T-9 defect it tracks sits in `IdempotencyStore`, which is also obstacle #1 in L-2 — a repair there touches this boundary, so the two should be sequenced, not merged. |
| **#2734** | closed/COMPLETED | **Preserved as verified fixed.** Its completion-stack is what makes L-2's obstacle #2 obsolete. Not reopened by this lane. |
| **#2733** | open, p1, beta-blocker | **Cross-referenced, not combined.** Pre-commit guard-refusal typing — a different failure mode from anything here. |
| **#2815** | open | Tenant-scoped declarative tenancy boundary; same inventory lane. Directly relevant to #2819's tenant requirement (L-3) and should be checked before a tenant field is designed. |
| **#2275**, **#1792**, **#1645**, **#1648** | closed | Origin of the SQLite-contention retry, the fail-open degraded marker, the actor three-state model, and the append-only raw-SQL bypass fix respectively. Historical context for L-1. |
| **#2177** | closed | Origin mission for `strict_audit_ledger` (F4) and the four-reason statement re-examined in L-2. |
| **#2985** | open | Anchor. `issue-coverage.md` assigns #2819 to the `entity-database-search-cache` lane as a `candidate`, "Unassigned / inventory-only-not-dispatched", with its own caveat that entries remain leads until source-checked. This lane performs that source check. |

**No new issue is filed.** L-6 is the only finding with no home; it is small and
squarely inside #2819's acceptance criteria, so it belongs there as a scoped
addendum rather than a separate ticket.

## Compatibility constraints on any repair

- `AuditWriterInterface`'s must-not-throw contract is relied on by ten registered
  listeners. A loss-proof path must be **added alongside** it, not by changing its
  semantics — #2819's own criteria require best-effort and loss-proof to remain
  "clearly separate".
- `AuditEventKind` is under a governance test pinning its cardinality; any
  application-contribution mechanism must not make that roster unverifiable.
- `StrictAuditLedgerInterface` lives in foundation (L0) specifically so the MCP
  write tier need not depend on `waaseyaa/audit`. A joined-writer contract must
  respect that layering or it will break the `core`-only boot.
- `strict_audit_ledger` and `audit_event` are append-only via
  `AppendOnlyAuditDatabase`; a joined write must not require UPDATE.
- Obstacle #3 (multi-connection) means any atomicity guarantee must be conditional
  and declared, not blanket.

## Focused acceptance criteria

Beyond #2819's own list, this lane's evidence adds:

1. Whatever design is chosen must state **which** of the four documented
   obstacles it resolves and which it accepts, and `mcp-endpoint.md:946-951` must
   be corrected — obstacle #2 is false at HEAD regardless of whether #2819 ships.
2. `StrictAuditLedgerInterface`'s docblock rationale is corrected in the same
   sweep (same stale claim, same root).
3. A dangling-reservation query is exposed to operators — CLI, scheduled check, or
   read model (L-6) — with a test proving a crash between reserve and finalize is
   surfaced and *not* auto-retried.
4. Any new descriptor is compared against `PrivilegedReadDescriptor` (L-3) and
   documented as convergent or deliberately divergent.
5. Tenancy is settled against #2815 rather than independently invented.
6. Best-effort and loss-proof paths remain distinguishable at the type level, so a
   caller cannot silently get the weaker one.
7. Any joined writer preserves L-8's prune-safety property and either goes through
   `AppendOnlyAuditDatabase` or documents why it bypasses it (L-10).

## Residual work not covered

- Whether any construction site reaches unkeyed custody (L-9) — the provider
  path does not, but every site was not enumerated.
- Whether `audit_event.id` is a strict gapless autoincrement, which L-9's
  mid-chain-insert reasoning assumes.
- Whether multi-connection deployment (obstacle #3) is a supported default.
- Whether `IdempotencyStore`'s transaction boundary can be exposed without
  breaking its replay-record atomicity.
- No executed proof for any finding.

## Recommended next disjoint lane

**Tenancy enforcement (#2815).** It is the dependency this lane surfaced —
#2819 cannot specify a tenant field without it, L-3 shows tenancy exists on one
descriptor and not the other, and no lane so far has audited whether a tenant
boundary is *enforced* anywhere rather than merely represented. Disjoint from all
ten lanes to date.
