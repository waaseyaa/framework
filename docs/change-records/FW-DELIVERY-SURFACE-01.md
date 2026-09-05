# FW-DELIVERY-SURFACE-01 — package-local public-surface declarations

- Issue: `#2901` (programme `#2527`, wave "efficient parallel agent delivery")
- Contract: `docs/specs/public-surface-declarations.md`
- Charter: `docs/specs/stability-charter.md` §2.5, §8.1 (amended by this record)
- Authority: repository-tracked source changes; forge-neutral

## Problem

`docs/public-surface-map.php` (719 dispositions across 53 packages) and its
companion `docs/public-surface-map.md` are edited by every package that adds a
contract shape. Two independent package changes therefore collide on the same
aggregate file, and each rebase re-imports the other's rows. The map is
authoritative for `public|internal` and cannot be inferred from syntax, so the
fix cannot be "generate everything": automation must never invent an API
commitment.

## Decision

1. **One editable plane.** Each package declares its own dispositions in
   `packages/<pkg>/public-surface.php`, a list of entries (`fqcn`,
   `disposition`, optional `purpose`, optional `ref`) plus optional free-text
   `notes`. A list — not a keyed array — so duplicate FQCNs survive `require`
   and are rejected instead of silently collapsed.
2. **Ownership is checkable.** An FQCN may be declared only by the package
   whose Composer PSR-4 prefix owns it. Every existing entry has exactly one
   owner (verified: 0 orphans at `5bac44286`). Declaring another package's
   symbol is an *orphaned* declaration and fails.
3. **Contradiction fails closed.** The same FQCN declared by two packages
   (even with the same disposition) is rejected; the message names both.
4. **The aggregates become derived.** `bin/generate-surface-map` composes
   the declarations into `docs/public-surface-map.php` (same return shape, so
   every `require` consumer keeps working) and `docs/public-surface-map.md`
   (layer → package → sorted symbols, shape derived from the AST/reflection,
   purpose from the declaration, notes rendered verbatim). Ordering and bytes
   are deterministic; `--check` proves it.
5. **The tracked/generated boundary.** PRs do not commit the aggregates.
   The parity gate accepts the tracked aggregate only if it is byte-identical
   to the merge base's **or** to a fresh generation from HEAD's declarations —
   never a hand edit. The governed release cut regenerates and commits both
   files, exactly as it compiles `CHANGELOG.md` from `changes/unreleased/`.
   Between releases the aggregate may lag; every authority check reads the
   declarations, not the aggregate.
6. **Authorization is unchanged in substance.** Removal, rename and public
   downgrade are compared between the merge base's *composed declarations*
   and HEAD's, and require the same newly-added exact-FQCN changelog-fragment
   directive as before (`SurfaceChangeAuthorization`). Historical changelog
   entries still authorize nothing. `extract` and `remove` remain valid
   dispositions with zero current uses.
7. **No broadening.** The migration carries the 719 dispositions verbatim.
   The 33 md rows that were never dispositioned (27 concrete/interface
   elements, 6 prose rows) are carried as package `notes`, not as new
   dispositions.

## Slice

Single review candidate: declaration library and scanner, generator, gate
rewrite at the same path (`tools/check-surface-parity.php`, so the preflight
roster and `surface-parity.yml` keep their references), 53 package declaration
files produced by a one-off migration script and then checked in, consumer
migration, contract spec with migration guide, charter amendment, and the
composition proof required by the acceptance criteria.

Shared files owned by Codex for this wave (`tools/preflight-gates.json`,
`.github/workflows/release-cut.yml`, `docs/specs/governed-gates.md`) are not
edited here; an exact integration patch is attached to the review candidate.

## Evidence

Recorded on the review candidate: red output of the composition proof before
the implementation (`bin/generate-surface-map` did not exist —
`SurfaceDeclarationCompositionTest` failed all three cases on
`assertFileExists`); green after `tools/lib/SurfaceScanner.php`,
`tools/lib/SurfaceDeclarations.php`, and `bin/generate-surface-map` landed.
`bin/migrate-surface-map` carried all 719 pre-migration dispositions (640
`public`, 79 `internal` — the change record's "704" estimate underweighted;
recounted directly against `docs/public-surface-map.php` at `5bac44286` per
the data-freshness rule of trusting the source over a summary) across 53
owning packages verbatim; `SurfaceMigrationFidelityTest` pins that fidelity
against the exact pre-migration commit. `bin/generate-surface-map --check`
passes on the migrated tree; two consecutive `--stdout=php` generations
hashed identically (sha256
`11901b25f921a2f3369b26430fc2f9cbf899da78999593361cebf38edddf9d92` at the
candidate head; `8d3250150e26ab989ec6473ae802fe19b4d737067ae93874eeaafad189b44f39`
after the review repairs below, which write FQCNs with single backslashes) —
determinism proof. The rewritten `tools/check-surface-parity.php` was
exercised against a hand-edited `docs/public-surface-map.php` (rejected,
naming the file, §6 boundary) and an emptied `packages/analytics/public-surface.php`
(rejected as `missing`, §4) before being reverted. Preflight (`--full`,
including `phpstan` and `check-dead-code`) and the three suites (Unit,
Integration, Architecture) were run on the exact candidate head; see the
commit series for per-commit preflight output.

Of the 382 `docs/public-surface-map.md` table rows, 349 joined to a governed
FQCN (196 by exact `prefix + Element` concatenation, 153 by the documented
unique-suffix fallback — the human-written doc often omits an intermediate
namespace segment); 33 joined to none (27 concrete/interface elements the
prior map never dispositioned, 6 prose method/constructor-argument rows) and
became package `notes` verbatim, never a new disposition. The design record's
original "31 concrete classes + 7 prose rows = 38" estimate does not survive
a recount against the actual files and is superseded by these verified
figures; see `designDeviations` in the delivery report for the full
accounting.

## Review repairs (adversarial review of the candidate)

The independent review ran the real gate against hand-built fixtures in a
disposable clone of the candidate head and found one authority-boundary
regression plus three deliverable defects, all repaired on the candidate:

1. **Empty merge-base map (blocking, fixed).** `tools/check-surface-parity.php`
   composed the merge base's declarations; a merge base that predates the
   plane (no `packages/*/public-surface.php` — including this candidate's own
   base `5bac44286`) composed to an *empty* map, so removal/downgrade
   authorization compared against nothing. Observed before the fix: removing
   the governed `public` entry `Waaseyaa\AI\Agent\AgentDefinitionRegistry`
   from `packages/ai-agent/public-surface.php` and running
   `bin/generate-surface-map --write` passed `--base=origin/main` with exit 0
   ("OK — public-surface parity verified"); the same tree against a
   post-migration base failed correctly. The pre-migration gate read the
   base's tracked `docs/public-surface-map.php` and would have caught it.
   Fix: when the merge base carries no declaration files the gate loads and
   validates the base's tracked aggregate instead (`loadBaseAggregate`), and
   a base with neither is exit 2. Observed after the fix: the same mutation
   against `origin/main` fails with "1 governed declaration(s) were removed
   without a newly-added exact-FQCN changelog-fragment authorization"; a base
   with neither declarations nor aggregate exits 2 naming the base; the clean
   candidate still passes. Spec §7 step 3 records the rule.
2. **Human view hid the disposition (fixed).** The generated
   `docs/public-surface-map.md` rendered all 719 entries — 79 of them
   `internal` — in one `Element | Type | Purpose` table under a header that
   said everything listed was public. Rows now carry a `Disposition` column
   and the header states that only `public` rows are commitments;
   `SurfaceDeclarationCompositionTest` asserts both a `public` and an
   `internal` row. Spec §6 records the column.
3. **Integration patch did not apply (fixed).** The fenced diff below failed
   `git apply --check` ("corrupt patch": hunk line counts and offsets were
   hand-written). It is now produced from real edits in a clone of the
   candidate and verified with `git apply --check` from the extracted fence.
   It also no longer flips `surface-parity` to `refresh.mode: auto`:
   `bin/refresh-governance-artifacts` runs a gate's `run` command without
   substituting `{base}`, so an `auto` surface-parity would always report
   "regenerated but the gate STILL fails", and
   `tests/Architecture/RefreshGovernanceArtifactsTest.php` asserts the gate is
   `manual` — the earlier patch would have broken the Architecture suite on
   landing. The gate stays `manual` with an instruction naming
   `php bin/generate-surface-map --write`; the roster-reading Architecture
   tests were run with the patch applied (75 tests, OK).
4. **Figures.** Spec §2/§10 said 704 entries and 38 note rows (31 + 7); the
   verified counts are 719 (640 `public`, 79 `internal`) and 33 (27 + 6),
   now stated consistently here and in the spec.
5. **Generated PHP view escaping (fixed).** The generated
   `docs/public-surface-map.php` wrote every key through `addslashes`
   (`'Waaseyaa\\Foundation\\…'`), so a line-level comparison with the
   hand-authored map at `5bac44286` differed on every row, and regex readers
   of the tracked view (`docs/audits/FW-ARCH-2026-08/tools/a0-inventory.mjs`)
   would capture doubled separators. Keys are now written with single
   backslashes as before; after stripping comments and blank lines and
   sorting, the regenerated map is line-for-line identical to the
   pre-migration map, and `require` of both yields the same array.

Every other fixture behaved as the contract requires (exit code, offending
message): remove a public entry → unauthorized removal; downgrade
public→internal → unauthorized downgrade, and passes only with a
newly-added `- Public surface deprecation:` line in a `.deprecated.md`
fragment (wrong FQCN case, wrong fragment type, and a directive already
committed on the base all still fail); hand-edited `docs/public-surface-map.php`
→ boundary failure naming the file; regenerated → pass; declaration added
with the aggregate left at merge-base bytes → pass; contract shape with no
declaration → `missing`; wrong-package declaration → `orphaned`; declared by
two packages → `contradictory`; duplicate within one file → `duplicate`;
an FQCN placed in `notes` → still `missing` (a note is never a disposition);
keyed `entries`, unknown key, non-array return → `invalid`;
public→`extract` without a directive → unauthorized downgrade; deleted
aggregate → failure naming the file.

## Integration patch (Codex)

Codex owns `tools/preflight-gates.json`, `.github/workflows/release-cut.yml`,
and `docs/specs/governed-gates.md` for this wave. This candidate does not
edit them; the diff below is generated from real edits to those three files
at the candidate head and verified with `git apply --check` (extract the
fence to a file and apply it at the repository root). `surface-parity` keeps
`refresh.mode: manual` (see "Review repairs" §3 for why `auto` is wrong for
a base-dependent gate); its instruction now names the regeneration command.
The release cut regenerates both aggregates and stages them with the release
commit, exactly as it compiles `CHANGELOG.md`. `docs/specs/governed-gates.md`
keeps the public-surface entry under judgment artifacts and explains the
declaration/aggregate split. `.github/workflows/surface-parity.yml` is
surface-specific and is edited directly by this candidate.

```diff
diff --git a/.github/workflows/release-cut.yml b/.github/workflows/release-cut.yml
index c9e2720c9..e56e7142c 100644
--- a/.github/workflows/release-cut.yml
+++ b/.github/workflows/release-cut.yml
@@ -284,6 +284,16 @@ jobs:
         # fails a different way. See the git add list below.
         run: bash tests/PackagedForm/check-s1-sqlite-artifact --write-dependency-authority
 
+      - name: Regenerate the public-surface-map aggregates
+        # packages/<pkg>/public-surface.php declarations are the editable
+        # authority (FW-DELIVERY-SURFACE-01 / #2901); the tracked
+        # docs/public-surface-map.{php,md} aggregates are derived views the
+        # release cut regenerates and commits, exactly like CHANGELOG.md is
+        # compiled from changes/unreleased/ in this same job. Declarations do
+        # not change during the version sweep, so ordering relative to it is
+        # immaterial; it must simply run before the release commit is staged.
+        run: php bin/generate-surface-map --write
+
       - name: Commit release and push the gate branch
         id: relcommit
         env:
@@ -297,7 +307,7 @@ jobs:
           # whose manifests disagree with its install or consumer metadata.
           # See alpha.178 (unstaged packages) and alpha.287 gate attempt 1
           # (unstaged skeleton and stale root-lock metadata).
-          git add -A -- CHANGELOG.md changes/unreleased changes/released VERSION composer.lock packages/*/composer.json skeleton/composer.json support/s1-sqlite-dependency-bytes.json
+          git add -A -- CHANGELOG.md changes/unreleased changes/released VERSION composer.lock packages/*/composer.json skeleton/composer.json support/s1-sqlite-dependency-bytes.json docs/public-surface-map.php docs/public-surface-map.md
           git commit -m "chore: release ${VERSION}"
           # The release commit goes to a throwaway gate branch FIRST. Main is
           # only fast-forwarded after Gate 2 proves CI green on this exact SHA.
diff --git a/docs/specs/governed-gates.md b/docs/specs/governed-gates.md
index 3f8bf5cd3..587c0186f 100644
--- a/docs/specs/governed-gates.md
+++ b/docs/specs/governed-gates.md
@@ -67,7 +67,12 @@ For every governed recorded artifact, one command knows how to repair it:
   what changed before committing.
 - **Judgment artifacts** (entries need human-authored rationale): getquery-bindings baseline
   (entries require `# reason` comments), dead-code baseline (policy: shrink-only), public surface
-  map, symfony-import allowlist, access-hardening, governed-secret-access and runtime-policy-custody baselines,
+  declarations (`packages/<pkg>/public-surface.php` — a `surface-parity` failure means a missing
+  declaration or an unauthorized removal/downgrade, which only a human can settle; the tracked
+  `docs/public-surface-map.php`/`.md` aggregates those declarations compose into are mechanical,
+  regenerated by `bin/generate-surface-map --write` at the release cut, and the refresh instruction
+  names that command — FW-DELIVERY-SURFACE-01 / #2901), symfony-import allowlist, access-hardening,
+  governed-secret-access and runtime-policy-custody baselines,
   php-coverage baseline. Refresh does **not** rewrite these; it detects staleness and prints the
   exact regeneration or hand-edit instruction for each.
 
diff --git a/tools/preflight-gates.json b/tools/preflight-gates.json
index 591759d8c..7c111e7bc 100644
--- a/tools/preflight-gates.json
+++ b/tools/preflight-gates.json
@@ -349,7 +349,7 @@
             "enforced_by": "workflow:surface-parity.yml#check-surface-parity.php",
             "refresh": {
                 "mode": "manual",
-                "instruction": "record each new public element's disposition in docs/public-surface-map.php; authorize a removal, rename, or public downgrade only with the exact current-change CHANGELOG grammar in stability-charter.md §8.1"
+                "instruction": "declare each new contract shape in its owning packages/<pkg>/public-surface.php; authorize a removal, rename, or public downgrade only with the exact current-change CHANGELOG grammar in stability-charter.md §8.1; the tracked docs/public-surface-map.{php,md} aggregates are derived views — regenerate them with `php bin/generate-surface-map --write` (the release cut does this), never by hand"
             }
         },
         {
```

## Codex integration and independent review

The reserved preflight-roster, governed-gates and release-cut patch is integrated
in this candidate. Release-cut generation and staging include both aggregate
files; refresh remains manual because classification and authorization require
maintainer judgment. No release operation was performed.

Independent review of candidate 801b6cb3ea15f63d71c298ff527d344e8cdfd9cf
reproduced a gate-level loadability regression: an interface declared in source
under the wrong PSR-4 filename passed the new AST-backed parity gate, whereas
the previous gate refused the same type as non-loadable. Source discovery must
remain distinct from actual autoloadability. The existing Integration test also
checks loadability; this finding does not claim that the entire CI pipeline
would accept the broken type. The repair restores actual autoloadability for every composed declaration and
rename target, with a real-gate wrong-filename refusal and correct-filename
positive control. Generator fixture AST behavior remains unchanged.

Independent real-tree probes also confirmed that a public-to-internal downgrade
without a directive fails, a historical CHANGELOG directive still fails, and a
newly added correctly typed exact-FQCN directive passes. Those controls exercised
the pre-migration aggregate fallback against the real candidate merge base.

The final migration regression exercises the real migrator against the in-tree
719-entry snapshot and compares its composed output exactly, including names
containing digits. It does not permanently constrain the live declarations to
that historical map: future internal-to-public promotions remain valid without
a deprecation directive, while the real parity gate continues to govern actual
base-to-head removals and downgrades. A second migration proves byte stability.
The loadability fixture creates its own complete local Git history, so shallow
CI source checkouts cannot affect its baseline comparison.

Focused integration verification passed 24 tests and 747 assertions, covering
migration fidelity, actual autoloadability, preflight roster parity, and release
workflow parity. Full qualification is bound separately to the final candidate
SHA; earlier-head suite results are not reused.