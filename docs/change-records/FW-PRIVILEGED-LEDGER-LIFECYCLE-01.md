# Privileged-read ledger volume and retention

Stable change ID: `FW-PRIVILEGED-LEDGER-LIFECYCLE-01`.
Date: 2026-10-19. Status: proposed repair scope; implementation not started.
GitHub mirror: [#3211](https://github.com/waaseyaa/framework/issues/3211).
Program: [#3118](https://github.com/waaseyaa/framework/issues/3118).
Owners: entity-storage (gateway probes and core writer), audit (strict receipt
custody and retention), foundation (boot composition), cli (operator surface).

## Outcome and authorization

Bound unnecessary privileged-ledger growth and provide a supported lifecycle for
retained receipt history without weakening protected-read authority. This record
owns two repair slices: repeated metadata-probe volume and receipt-aware
retention. It does not claim package convergence or completion of the Framework
audit program.

The authorized documentation task creates and publishes this change record and
its GitHub issue.
Design choices and acceptance below are proposals, not changes to the live
contract. Runtime implementation, integration, release, downstream adoption and
production deletion or compaction remain outside this documentation task.

## Evidence and limits

Framework source baseline: `9e23c8b6b3ac14b8000653ec197ab8cc77bd0d49`, also
verified as GitHub main during intake. Source review establishes:

- `AbstractKernel::boot()` calls `validateQueryDefinitions()` on each fresh
  ordinary kernel boot. `DefinitionValidator::validateAll()` plans probes for
  every indexed field.
- The gateway and both database-backed gateway writers retain one reservation
  and one terminal event per successful probe. Thus N supported indexed fields
  produce 2N ledger rows per complete validation. The #3204 batching repair
  reduces commits but intentionally preserves this row count.
- `privileged_read_ledger` also contains actual protected reads and validation
  reads. Probe volume must not be confused with all ledger traffic.
- `audit:prune`, `AuditCheckpointBuilder` and `AuditChainVerifier` operate on
  `audit_event` and its checkpoints. They do not provide a privileged-ledger
  retention or verification boundary. The append-only decorator protects the
  privileged ledger, but that alone does not establish archival integrity.
- Both the audit migration and core schema define receipt lookup and unique
  receipt/event indexes, with no dedicated age-selection index.

The consumer handoff `FETDER-PRODUCTION-DB-SIZING-20261019.md`, retained under
the consumer's `docs/changes/`, reports a read-only measurement on 19 October:
4,438,188,032 of 4,446,187,520 allocated database bytes (99.82%) attributed to
the ledger and its indexes, with zero free pages. The dominant allocation was
derived by subtracting measured other-object pages, not by completing a full
ledger scan. The installed package cohort was alpha.305.

A bounded sample of the last 10,000 sequence positions spanned 123.27 seconds
and contained protected User reads and many query-support probes. Sequence
positions are not an exact surviving-row count; this sample is not a historical
producer breakdown, evidence of orphaned receipts, or a long-term growth
forecast. This intake did not query production again. No private descriptors,
identities, receipt IDs, credentials or row contents are included here.

## Slice 1: reduce repeated metadata-probe volume

Owner: entity-storage, with foundation composition and audit contract review.

Establish a narrow boundary for pure query-capability validation. Determine
whether an unchanged, verified definition/backend generation can reuse its
validation result without invoking the backend again. Do not merely suppress
receipts around an invocation: the present contract requires every gateway
invocation to be audited.

Before implementation, specify the complete validity identity: field/query
definitions, backend registration and fingerprint, implementation provenance,
and any schema, configuration, tenant or policy inputs actually affecting the
answer. Unknown dependencies, dynamic/custom backends, stale evidence and
unavailable reuse state must retain the strict validation path or refuse as the
owning contract requires. Arbitrary extension callbacks must not be assumed
pure. Define safe cross-process reuse and concurrent publication before claiming
a reduction across requests; an instance-local cache alone is insufficient.

Acceptance:

- [ ] Record the proposed contract and exact reusable/non-reusable cases in
  `entity-field-read-boundary.md`, `entity-system.md` and `ocap-audit-log.md` as
  applicable; preserve the existing contract until the reviewed change lands.
- [ ] Demonstrate baseline row/commit counts with synthetic indexed fields and
  repeated fresh kernels. Establish an explicit candidate row-volume bound,
  including any validation-summary events, before implementing the repair.
- [ ] Prove that unchanged eligible definitions meet that bound across real
  cold/warm requests and process restarts; measure latency separately.
- [ ] Changed definitions, fingerprints and every declared validity input
  invalidate reuse. Corrupt, forged, partial or stale reuse records cannot grant
  success; cover concurrent creators and failed publication.
- [ ] Unsupported fields still refuse boot. Required revalidation preserves
  reserve-before-invocation durability, terminal persistence before success,
  invocation order, fingerprint/output checks and interrupted-attempt evidence.
- [ ] Real protected reads, gateway read/write/delete calls and non-eligible
  extensions retain their current authorization and truthful receipt semantics.
- [ ] Qualify both the audit-provider and core-fallback compositions and retain
  the independent SQLite contention discriminators from #3204 and #3183.

## Slice 2: supported receipt-aware retention

Owner: audit and entity-storage, with cli operator integration.

Define retention by receipt kind, terminal outcome and required evidence, then
provide a supported preview and bounded purge operation. The application owns
the required retention periods; this record chooses no age threshold and does
not inherit the historical 90-day proposal from #208.

Inventory every writer and reader/reference consumer before choosing a storage
design, including actual privileged reads, gateway operations, validation reads
and the core-only installation profile. A complete receipt consists of its
reservation and exact terminal event. Eligibility must use terminal-event age
and exclude unfinished, malformed, held or still-referenced receipts. Define an
explicit reconciliation route for unfinished receipts instead of treating age
as success or silently deleting them.

Acceptance:

- [ ] Specify policy decoding, actor authority, outcome handling, time/cutoff
  semantics, supported installation profiles, required references/holds and
  retained evidence. Invalid policy or unavailable authority prevents deletion.
- [ ] Provide a dry run reporting the cutoff, candidate receipt and row counts,
  exclusions, and a clearly complete or partial scan result. Define how apply
  revalidates a preview under concurrent writes; stale previews are not authority.
- [ ] Delete complete eligible receipt pairs atomically in bounded batches with
  a fixed selection horizon, bounded runtime and resumable/idempotent progress.
  Retain the unique receipt/event invariant and fail-closed replay behavior.
- [ ] Add coordinator-owned schema/index changes only where a measured query
  plan requires them. Prove bounded selection at representative ledger sizes;
  no unbounded table scan or long write transaction on the request path.
- [ ] Preserve ordinary append-only writer protections. Use a narrowly scoped
  retention authority and verifiable evidence of authorized removal. Specify
  whether archive/export is required and which trust boundary verifies it;
  ordinary audit checkpoints do not already authenticate this ledger.
- [ ] Prove concurrent reservation/finalization and prune behavior, fresh
  terminal events, mixed outcomes, malformed/orphaned receipts, reference/hold
  exclusions, repeated runs, and receipt replay after pruning.
- [ ] Inject selection, deletion, evidence-write/export and commit failures,
  including interruption between batches. No partial pair deletion or false
  success is allowed. Required evidence must survive or cause safe refusal.
- [ ] Rehearse on isolated synthetic data and then an authorized isolated
  consumer snapshot. Measure logical reclamation and physical file allocation
  separately. Production compaction requires its own qualified operational plan.

## Related work and sequencing

Statuses below were checked on 2026-10-19; linked issues retain their own scope.

| Issue | Intake status | Relationship |
| --- | --- | --- |
| [#3204](https://github.com/waaseyaa/framework/issues/3204) | Closed | Boot-probe commit batching. Keep its durability/refusal guarantees; row-volume reduction is new work. Portable record: [FW-BOOT-QUERY-AUDIT-01](FW-BOOT-QUERY-AUDIT-01.md). |
| [#3184](https://github.com/waaseyaa/framework/issues/3184) | Open | Closed User status batch API. Coordinate actual-read cost, but batching still retains per-user receipts and does not supply retention. |
| [#3183](https://github.com/waaseyaa/framework/issues/3183) | Closed | Privileged-read finalization contention. Preserve the write-before-receipt-read repair and its regression controls. |
| [#3015](https://github.com/waaseyaa/framework/issues/3015) | Open | Ordinary audit checkpoint concurrency/export. Reuse applicable custody lessons without claiming it covers this ledger. |
| [#3010](https://github.com/waaseyaa/framework/issues/3010), [#3011](https://github.com/waaseyaa/framework/issues/3011) | Open | Existing retention scan-result and policy-decoding owners. Coordinate equivalent requirements without duplicating or closing their broader work. |
| [#3118](https://github.com/waaseyaa/framework/issues/3118) | Open | Framework audit-program relationship. This record is a bounded repair scope. |
| [#3199](https://github.com/waaseyaa/framework/issues/3199), [#3123](https://github.com/waaseyaa/framework/issues/3123) | Open | Boot journey and Foundation assessment. Add concrete volume acceptance where relevant; do not restart unrelated audits. |

No dedicated growth/privileged-retention issue was located in the intake search
of open and closed Framework issues. Neither slice reopens #3204 or #3183 as a
failed repair. Both can be designed independently. Slice 1 has immediate value
for ongoing write volume; slice 2 remains necessary for legitimate read history
and existing allocation. Neither may be declared complete because the other
reduces a benchmark.

## Design, verification and closure

Use maintained components for generic mechanisms: evaluate the applicable
supported Symfony facilities against source/tests and official documentation
before adding caching, locking, scheduling or CLI infrastructure. Reuse existing
Doctrine transactions and coordinated schema ownership. Waaseyaa retains receipt
authority, validity, retention and refusal policy. Record any custom exception's
capability gap, owner and review trigger; no component choice is settled here.

Follow design-first, discriminating red/green tests and the local testing policy.
Name exact baselines/candidates, dependency cohorts, commands, supported host,
row/commit counts and failure evidence. Use focused gateway, definition-validator,
strict-ledger, pruning and full-kernel caller checks; synthetic file-backed SQLite
and separate processes are required for concurrency claims. Installed consumer
qualification is separate from source tests. Do not install borrowed dependencies
or infer deployment readiness from a source pass.

Independent review is required before integration because these slices affect
authorization and persistence. The current planning record receives self-review
and document/link checks only; no runtime tests or repair acceptance are claimed.
Track each slice as proposed, designed, implemented, reviewed and qualified.
Close the issue only with both slices' exact evidence, or an explicit maintainer
decision that names residual ownership. This record itself does not authorize
implementation, release, production cleanup, audit disablement or raw application
SQL deletion.
