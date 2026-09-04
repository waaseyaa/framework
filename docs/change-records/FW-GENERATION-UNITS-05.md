# FW-GENERATION-UNITS-05 — dormant unit reconciliation and verification

Date: 2026-09-04. Forge mirror: #2846. Authority: ADR-025 D-2 and D-12.1 slice 5.
Base: d7aa2ade63c1efa5118eede25bcc083d0a9e260d. Design a0302be8e1141318dce2b097f9dd5ad8bbebd3b2 was replayed onto this base without conflict; its proposals are superseded below.

## Review decision

The previous proposal weakened staged activation by widening the live reader on the assumption that nobody could possess unit metadata. Hand-authored metadata and interrupted journals are observable inputs. Legacy initialize, doctor, report, and recovery paths must preserve their accepted inputs, errors, bytes, and exit statuses. New behavior stays behind internal seams until slice 8.

GeneratedSite, GeneratedArtifact, and SiteArtifactRenderer remain unchanged under D-2.6. GeneratedSite certifies the root projection. The existing transaction authority composes and verifies the full document. The renderer fixture alone cannot establish publication-byte identity; first add a service-level oracle and demonstrate a deliberate publication mutation fails it.

A Warning does not generally mean nonblocking. The dormant unit report permits only the exact seeded-content-modified finding to be nonblocking. Missing files and mode drift remain blocking. Unit and disposition are surfaced in unit diagnostics without changing legacy serialization. Registration diagnostics remain slice 6.

## Work packages and exclusive ownership

- Oracle worker: new GenerationPublicationIdentityTest.php only. Establish root-only publication bytes and hostile mutation detection before service edits.
- Engine worker: SiteInitializationService.php and new GenerationUnitEngineTest.php only. Internal roster reader, reconciliation, composition, retirement, shared journal/recovery, and focused hostile tests.
- Primary: SiteDoctorService.php, doctor report/types and their tests; this record, historical design review note, release fragment, surface maps, and boundary tests. Primary owns architecture and GitHub custody.
- Scouts: read-only GitHub/project/worktree inventory and test/source maps. No ownership of production files.

## Engine and activation boundaries

Extend the existing reader with a default legacy mode and an internal unit-aware mode. The dormant evaluate(ArtifactPlan) seam uses the latter. Internal preparation carries unrelated rows verbatim, checks only supplied or retired ownership, and composes metadata last through the existing publisher. No second containment check, lock, transaction, journal, ownership document, or receipt sink is introduced.

The empty production seeded compiler allowlist refuses new seeded admission. Persisted seeded fixtures remain readable for carry-forward, doctor and retirement proofs; fixture construction does not claim a currently authorized seeded compiler. Registrations remain refused until slice 6; additive evolution remains unavailable until slice 7. Root metadata and default journal bytes remain unchanged.

Retirement extends the existing publisher and rollback with internal remove operations. Legacy recovery rejects their journal shape until activation. No public controlled-apply operation is added in this slice. Tests exercise private seams against real temporary files and the existing lock/publisher.

Doctor gains a dormant unit-aware inspection path in the same candidate. It validates the same roster, compares the root projection to the renderer, and checks non-root rows by disposition. No handler calls this path before activation.

## Required evidence

1. Root publication oracle passes before relocation; a deliberately corrupted publication fails it.
2. Legacy root bytes, messages, exit states, metadata and journal rejection remain unchanged, including hand-authored future-format input.
3. Canonical unit roster, ownership uniqueness, unknown ownership, first-owner collision, frozen supplied sets, no unrelated-byte enforcement, and metadata-last composition are tested.
4. Multi-unit doctor agrees with composed root projection; modified seed content alone is nonblocking; missing seed, managed drift and mode drift block; owner/disposition are visible.
5. Retirement probes cover before/after remove, metadata failure, interrupted rollback, bytes/modes, empty-directory restoration, substituted backup, reappeared targets, symlink/hardlink refusal and committed cleanup retry.
6. Every material guard is challenged by a deliberately introduced rival implementation. Broken probes are agent evidence rather than framework defects.
7. Full preflight and split Unit/Integration/Architecture suites pass on the exact pushed candidate; hosted checks bind that SHA. Governed merge only, with no active release split/fan-out.

## Deferred and completed state

Implementation and independent recovery review are complete behind dormant seams; publication qualification is running. Slice 8 remains the only activating candidate. No make/scaffold handler migration, Composer registration semantics, eligibility expansion, release, or deployment is included. Existing Claude-owned #2870/#2780/#2836 worktrees are preserved and excluded.

## Review evidence before publication

The publication oracle passed for POSIX and the injected Windows profile before the execution service changed. Appending a newline at metadata staging failed both cases; the original source was restored before implementation began. The immutable renderer fixture remains unchanged.

The dormant doctor suite proves root projection, disposition-aware byte drift, missing files, mode drift, duplicate ownership, symlink/hard-link refusal, and extension-region handling. The initial extension probe searched for a literal backslash-n and made no edit; primary review rejected that false-positive proof. The corrected test requires exactly one replacement. A rival doctor ignoring extension-region metadata then fails the corrected test. This was an agent verification defect, not a framework defect.

A rival report policy that treats every Warning as nonblocking fails the narrow-notice test. A rival handler calling inspectUnits before activation fails the boundary test. Restored controls pass. The boundary test also pins the seeded compiler list to its exact empty value.

The first focused coverage run passed 158 tests / 634 assertions and covered 344 of 390 executable changed lines (88.21%, threshold 80%). This is worktree evidence, not hosted or final-head qualification; later revisions require re-verification.

D-11's general claim that forged ownership can only block writes cannot describe explicit retirement: D-2.3 authorizes deletion based on recorded ownership plus private-file and digest proofs. The reader validates shape and safety, not authenticity. This implementation follows the specific retirement rule, forbids transaction-control ownership, and makes no claim of authenticated metadata provenance.

The engine hostile probes killed rival implementations bypassing first-owner collision, carry-forward, backup integrity and control-path ownership. Recovery review independently reproduced and corrected three draft defects: a pending removal marker could discard recovery evidence after deletion, reappeared matching bytes with a changed mode could be modified during rollback, and an unknown child in a reappeared directory could be merged with restored artifacts. Exact restored tuples remain admissible so a second interruption can recover. A fourth probe reproduced interruption after rollback cleanup removed the backups but before journal deletion. Unit-aware recovery now permits cleanup-only retry after proving every original file digest/mode, every originally absent target remains absent, and every directory matches the prior state. The independent reviewer confirmed the exact-state retry succeeds and drift retains the journal. The final engine suite passes 54 tests / 235 assertions.

The supplementary publication oracle also exercises dormant root-plan preparation and the shared publisher under its lock for POSIX and injected Windows profiles. Together with the original legacy cases, all four cases pass (8 assertions); both routes preserve renderer metadata bytes.

Worktree qualification passed full preflight (41/41), Unit (14,115 tests / 240,060 assertions), Integration (2,311 / 11,736), and focused coverage (175 / 735; 418 of 467 changed executable lines, 89.51%). The three S1 archive proofs deliberately refuse dirty package bytes and will run against the committed revision. The fourth Architecture failure was the slice-4 assertion forbidding all coded refusals: it is now pinned to the one completed dormant execution authority, with all entrypoints still barred. A deliberately added second production caller fails the narrowed guard; restored boundary tests pass (5 / 97). Final immutable-revision and hosted evidence belongs to the PR, not these worktree counts.
