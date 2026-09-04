# FW-GENERATION-UNITS-06 — dormant registration reconciliation

Date: 2026-09-04. Forge mirror: #2846. Authority: ADR-025 D-2.1a, D-6.6 and D-12.1 slice 6.
Base: 7a567c07cc91c35cd4d1dab60eeb1e08c2684d1f (slice 5, its telemetry and the independently merged #2836 and #2870 fixes). Implementation began on d6ec60b583bf8aef58e7c0121c58c5b174e963f4; rebase was conflict-free and changed no slice-6 source bytes.

## Scope and activation

Implement the registration roster, disposition-specific reconciliation, the transactional Composer merge and registration doctor together. Extend only the dormant unit-aware path. Legacy initialize, metadata acceptance, recovery and strict doctor remain unchanged. Handler migrations, controlled apply and stale-plan enforcement remain slice 8; additive evolution remains slice 7. The seeded compiler allowlist remains empty.

ArtifactPlan already has its required registrations list. Persisted metadata has an optional registrations member, omitted when empty. These are distinct envelopes with intentionally distinct presence rules, not a contradiction or a reason to widen the pure plan type.

GeneratedSite, GeneratedArtifact and SiteArtifactRenderer stay unchanged. Composer content is never recorded as a wholly owned artifact. A private transaction item may carry the reviewed replacement bytes through the existing publisher, but ownership remains the individual registration roster. No second journal, lock, reader authority or containment policy is introduced.

## Execution contract

- The existing metadata reader in unit-aware mode validates the optional closed roster. Root ownership is absence of unit. Non-root rows name a recorded unit. FQCNs are globally unique and rows retain exact plan fqcn/group values. Duplicate ownership or unknown owners are GEN012; malformed shape, member values or order are GEN015. Empty rosters are omitted.
- Existing Composer state is validated through one internal execution-authority reader shared with doctor. Present providers must be a list of unique nonempty strings, whether or not this plan claims any; invalid state is GEN014. Preserve JSON object/list distinctions and unrelated application values. Missing Composer with no registration work must not perturb legacy-shaped root fixtures; creation of registrations must not invent a new application manifest.
- Ownership is decided before merge: a different recorded owner or an unowned pre-existing provider entry is GEN012. The latter must name safe operator options, without inventing adoption. A simultaneous retirement never enables same-plan capture.
- Unrelated registration rows carry forward. Managed/root supplied rows may add, drop, change group, and restore a missing Composer entry. Group-only changes update metadata without a Composer write. Seeded existing rows must match the recorded fqcn/group set or refuse GEN013; missing entries remain missing. Seeded creation stays closed in production until compiler migration, while persisted fixtures exercise its semantics.
- Retirements withdraw exactly their own recorded entries, tolerating an already absent entry, and preserve every unowned entry. Registration-only units become expressible; the empty-new-unit guard still refuses a unit owning neither files nor registrations.
- Composer changes preserve the project's indentation/newline conventions and all unrelated JSON values. An unchanged merge preserves the original bytes. Its observed digest is already in ProjectStateIdentity; enforcement under the lock remains slice 8. Publication and rollback use the existing transaction with metadata last.

## Doctor contract

The renderer certifies root files, while root registrations may be declared independently by a root ArtifactPlan. Strip the whole registration roster from the file-renderer projection, then validate registrations separately using the shared Composer reader. Otherwise a valid root registration would contradict the unchanged renderer envelope.

Report FQCN absence with owner and disposition: managed/root blocks, seeded is a distinct nonblocking notice. Unrelated well-formed user entries are invisible. Invalid roster or provider state blocks. Group is metadata-only and has no Composer representation to compare. The generation report's nonblocking exception must remain a closed list of exact finding IDs at Warning severity; legacy strict policy stays unchanged.

## Ownership and evidence

- Engine worker: SiteInitializationService.php, new GenerationRegistrationEngineTest.php, and only specifically authorized updates to obsolete slice-5 registration refusal tests. No doctor, handler, map or governance edits.
- Primary: doctor service/report and new registration doctor tests; this record, specs, release fragment and activation boundary tests. Architecture and GitHub custody remain primary.
- Cheap fixture worker: a separate new Composer formatting/identity fixture test, after the internal reader/preparation contract is fixed. No shared source edits.
- Independent reviewer: read-only probes of ownership, seeded apply-once, Composer substitutions and retirement rollback. No production edits.

Required red tests cover every D-2.1a transition, every GEN012/013/014/015 negative separately, registration-free byte identity, root projection, registration-only units, unowned-entry preservation, no-op/group-only byte identity, formatting/object-shape preservation, mode/link/substitution refusal, metadata failure and interrupted rollback, and legacy future-format rejection. Hostile rivals must demonstrate guard sensitivity before publication. Tests must distinguish seeded fixtures from authorization to admit a new seeded compiler.

The engine design review is complete. Fixed internal seams are readComposerProviderState() returning exists/raw/sha256/mode/providers plus private edit spans, reconcileRegistrations() returning roster and optional composerMerge, prepareUnitPlan() returning that composerMerge alongside its current members, and publish(..., ?array $composerMerge = null). Composer merge uses an internal composer-merge journal item with its original ordinary permission bits. Default legacy journal validation rejects it.

The formatter validates JSON, then edits only the providers value span or inserts missing members at the deepest existing ancestor. It preserves unrelated byte lexemes, including large numbers, escapes and duplicate unrelated keys. Duplicate keys on the targeted extra/waaseyaa/providers chain are ambiguous and refuse GEN014. Missing Composer is an absent observation: no-op and already-absent withdrawal remain absent; required additions or restoration refuse GEN014. The same observed Composer digest is passed into project-state capture, rather than reading a second version after merge preparation.

A supplied managed registration-only unit whose final registration is removed is elided when it owns neither artifacts nor registrations. This follows D-2.1's state-dependent roster presence and D-2.1a's explicit managed removal permission. It does not add path retirement: frozen file sets still forbid file drops; seeded removal still refuses; unrelated units are carried verbatim. No tombstone authority is introduced. Tests must prove no surviving owner or state is discarded.

Implementation follows the red tests and file ownership above. No release, deployment or other lane is authorized by this record.

## Review evidence

The initial engine matrix failed before implementation (33 cases); the initial doctor matrix also failed before implementation (16 cases). The implemented engine subsequently passed 40 tests / 138 assertions before independent recovery review. The primary doctor and corrected formatting controls passed 23 tests / 94 assertions.

Primary review rejected the cheaper worker's original formatting fixture evidence: escaped quote assertions and invalid newline fixtures did not test the claimed behavior, and the group assertion read a nonexistent preparation member. Fixtures now validate JSON before invocation, observe the published roster, include an actual Unicode escape, and compare all unrelated Composer bytes. This is an agent verification defect, not a framework defect.

Deliberate rivals for skipped registration doctor checks, renderer projection retaining registration rows, a broad Warning exception, and premature entrypoint access all failed their corresponding tests. Engine rivals permitting unowned adoption, cross-owner reassignment, seeded declaration changes and a late Composer rehash also failed. Sources were restored after every probe. Initial focused coverage before recovery repair was 243/259 changed executable lines (93.82%). This is draft evidence and is not immutable-candidate qualification.

Independent review then reproduced a draft recovery defect: an interrupted Composer merge whose target had original bytes but a changed mode was silently chmodded back and its recovery journal deleted. Composer remains application-owned; bytes alone cannot establish a known recovery tuple. Regression tests and a bounded composer-merge preflight must prove exact state-specific digest/mode admission before any restoration or cleanup. Legacy replace semantics remain outside this change.

Primary independently reproduced all three recovery failures before repair: original bytes with changed mode, installed bytes with changed mode, and a pending marker after replacement. Five exact-state retry cases already passed. The corrected preflight validates every Composer backup and admits only the prior tuple for pending items, or prior/installed tuples for installing/applied items. All eight cases then passed (51 assertions); legacy replace paths are unchanged. Full preflight passed 41/41, and repaired focused coverage passed 159 tests / 680 assertions with 261/278 changed executable lines covered (93.88%). These counts precede later test-only fixture additions.

The reviewer also demonstrated that private publication can overwrite a Composer edit made after preparation. That observation is valid but was adjudicated as the already named slice-8 stale-plan obligation, not a newly introduced slice-6 repair: all private prepared artifact publication currently relies on the future controlled-apply gate. The before_sha256 observation remains carried, and activation must compare the complete ProjectStateIdentity under the lock before publication. No per-Composer second stale authority is introduced here, and no concurrent-edit protection is claimed for the dormant slice. The probe is retained as activation evidence.

Independent bounded review closed with no remaining in-scope production defect. The final primary backup substitutions (different bytes and different mode on a pending merge) also refuse without altering the target or deleting recovery evidence; the recovery matrix passes 10 tests / 65 assertions. Additional roster fixtures cover every named GEN015 malformed-value case, and formatting fixtures cover multiline CRLF add/withdraw plus insertion into nonempty ancestors. The exact-head PR and hosted checks remain the publication evidence.

The initial committed candidate 0294cd1475a2a6b96cb003c8d0f76199d6c8e823 passed full preflight 41/41, Unit 14,201 / 240,421, Integration 2,311 / 11,736 and Architecture 592 / 32,041, including clean-archive proofs. The final candidate incorporates the independently merged main change and is requalified before publication.

Candidate fdf47944bd5b0b02add3232e1f4b87867c95c110 then passed preflight 41/41, Unit 14,207 / 240,430, Integration 2,311 / 11,736 and Architecture 592 / 32,041. The independent #2870 cleanup fix merged while PR #2887 started CI; that obsolete run was canceled and the candidate rebased again without slice-6 source changes. Exact updated-head qualification is required before merge.
