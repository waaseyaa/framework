# FW-DELIVERY-SURFACE-01 — package-local public-surface declarations

- Issue: `#2901` (programme `#2527`, wave "efficient parallel agent delivery")
- Contract: `docs/specs/public-surface-declarations.md`
- Charter: `docs/specs/stability-charter.md` §2.5, §8.1 (amended by this record)
- Authority: repository-tracked source changes; forge-neutral

## Problem

`docs/public-surface-map.php` (704 dispositions across 53 packages) and its
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
7. **No broadening.** The migration carries the 704 dispositions verbatim.
   The 38 md rows that were never dispositioned (31 concrete classes, 7 prose
   rows) are carried as package `notes`, not as new dispositions.

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
`11901b25f921a2f3369b26430fc2f9cbf899da78999593361cebf38edddf9d92`) —
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

## Integration patch (Codex)

Codex owns `tools/preflight-gates.json`, `.github/workflows/release-cut.yml`,
and `docs/specs/governed-gates.md` for this wave. This candidate does not
edit them; the following is the exact change this candidate needs landed in
each, once the shared-file lane picks it up. `.github/workflows/surface-parity.yml`
is surface-specific (not one of the three shared files above) and IS edited
directly by this candidate — its diff is not repeated here.

### `tools/preflight-gates.json` — surface-parity refresh becomes mechanical

The migration makes `docs/public-surface-map.php`/`.md` regeneration a pure
function of the tracked declarations (`bin/generate-surface-map --write`),
with no human judgment left in the loop — the same "mechanical artifact"
shape as the S1 rosters, not the "judgment artifact" shape the gate carried
before this PR.

```diff
--- a/tools/preflight-gates.json
+++ b/tools/preflight-gates.json
@@ -348,8 +348,8 @@
             "profile": "default",
             "enforced_by": "workflow:surface-parity.yml#check-surface-parity.php",
             "refresh": {
-                "mode": "manual",
-                "instruction": "record each new public element's disposition in docs/public-surface-map.php; authorize a removal, rename, or public downgrade only with the exact current-change CHANGELOG grammar in stability-charter.md §8.1"
+                "mode": "auto",
+                "write": "php bin/generate-surface-map --write"
             }
         },
```

The gate's `repair` line stays accurate as written (it already talks about
map entries loadable and the CHANGELOG directive, which is still how you fix
an actual `check-surface-parity.php` failure — `refresh` is a different
concern: repairing a *stale but otherwise valid* aggregate, which `--write`
now does unattended).

### `.github/workflows/release-cut.yml` — regenerate the aggregates in the release commit

Insert a step that runs `bin/generate-surface-map --write` after the
internal-version sweep (declarations do not change during the sweep, so
ordering relative to it does not matter, but it must run before the release
commit is staged) and extend the `git add -A --` list so the regenerated
files are part of the release commit, exactly like the S1 dependency-byte
authority already is.

```diff
--- a/.github/workflows/release-cut.yml
+++ b/.github/workflows/release-cut.yml
@@ -272,6 +272,12 @@
         run: bash tests/PackagedForm/check-s1-sqlite-artifact --write-dependency-authority
 
+      - name: Regenerate the public-surface-map aggregates
+        # Package-local packages/<pkg>/public-surface.php declarations are the
+        # editable authority (FW-DELIVERY-SURFACE-01 / #2901); the tracked
+        # docs/public-surface-map.{php,md} aggregates are derived views the
+        # release cut regenerates and commits, exactly like CHANGELOG.md is
+        # compiled from changes/unreleased/ in this same job.
+        run: php bin/generate-surface-map --write
+
       - name: Commit release and push the gate branch
         id: relcommit
         env:
           VERSION: ${{ inputs.version }}
         run: |
           git config user.name "github-actions[bot]"
           git config user.email "41898282+github-actions[bot]@users.noreply.github.com"
           # CRITICAL: stage every file mutated by the internal-version sync:
           # package manifests, the create-project skeleton, and copied path-
           # package metadata in the root lock. Omitting any one creates a tag
           # whose manifests disagree with its install or consumer metadata.
           # See alpha.178 (unstaged packages) and alpha.287 gate attempt 1
           # (unstaged skeleton and stale root-lock metadata).
-          git add -A -- CHANGELOG.md changes/unreleased changes/released VERSION composer.lock packages/*/composer.json skeleton/composer.json support/s1-sqlite-dependency-bytes.json
+          git add -A -- CHANGELOG.md changes/unreleased changes/released VERSION composer.lock packages/*/composer.json skeleton/composer.json support/s1-sqlite-dependency-bytes.json docs/public-surface-map.php docs/public-surface-map.md
           git commit -m "chore: release ${VERSION}"
```

### `docs/specs/governed-gates.md` — move "public surface map" to the mechanical bullet

§2's artifact classification lists "public surface map" under **Judgment
artifacts** ("Refresh does not rewrite these; it detects staleness and prints
the exact regeneration or hand-edit instruction"). After this PR it belongs
under **Mechanical artifacts** instead — `bin/generate-surface-map --write`
is deterministic and needs no human-authored rationale, the same as the S1
rosters and the dispatcher-key baseline.

```diff
--- a/docs/specs/governed-gates.md
+++ b/docs/specs/governed-gates.md
@@ -63,13 +63,13 @@
 - **Mechanical artifacts** (regenerable with no human judgment): the four S1 rosters
   (`support/s1-*-roster.json`) and the dispatcher-key baseline. Refresh regenerates them via the
-  verifiers' own write modes, then prints the resulting `git diff --stat` so the operator reviews
-  what changed before committing.
+  verifiers' own write modes, then prints the resulting `git diff --stat` so the operator reviews
+  what changed before committing. The public-surface-map aggregates
+  (`docs/public-surface-map.php`/`.md`, composed by `bin/generate-surface-map` from
+  `packages/<pkg>/public-surface.php` declarations — FW-DELIVERY-SURFACE-01 / #2901) are also
+  mechanical: `--write` is a pure function of the tracked declarations, with no
+  human-authored rationale involved.
 - **Judgment artifacts** (entries need human-authored rationale): getquery-bindings baseline
   (entries require `# reason` comments), dead-code baseline (policy: shrink-only), public surface
-  map, symfony-import allowlist, access-hardening, governed-secret-access and runtime-policy-custody baselines,
+  symfony-import allowlist, access-hardening, governed-secret-access and runtime-policy-custody baselines,
   php-coverage baseline. Refresh does **not** rewrite these; it detects staleness and prints the
   exact regeneration or hand-edit instruction for each.
```

This candidate carries a lowercase `spec-reviewed: docs/specs/governed-gates.md
- refresh classification for public-surface-map moves to mechanical, patch
above pending Codex's shared-file landing` trailer on its slice-3 commit so
the drift detector does not flag this spec as silently stale in the meantime.
