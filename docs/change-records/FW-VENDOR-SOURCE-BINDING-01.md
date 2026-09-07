# FW-VENDOR-SOURCE-BINDING-01 — candidate-local first-party autoload maps

- Issue: `#2972`
- Contract: `docs/specs/governed-gates.md`
- Extends: `#2926` vendor-freshness precondition
- Authority: root/path-package Composer declarations and generated Composer maps

## Problem

The existing freshness check compared locked and installed package identities
and required declared PSR-4 namespace keys. Two worktrees can have identical
lock metadata while one candidate's `vendor/` or generated runtime map still
points at the other checkout. That state can run another candidate's
first-party code through an expensive test suite without failing the existing
precondition.

The reproduced negative fixture kept lock and installed metadata equal and
kept `autoload_psr4.php` candidate-local while binding the same namespace in
`autoload_static.php` to a donor directory. The prior helper returned `null`.

## Decision

Extend `bin/lib/vendor-freshness.php`; do not add another runner or mutate
`vendor/`. The helper remains dependency-free and does not load
`vendor/autoload.php` or any application file.

Candidate ownership is declared, not inferred from names:

- root `autoload` and `autoload-dev` entries belong to the candidate;
- a locked `dist.type=path` package whose URL is lexically inside the
  candidate belongs to the candidate;
- other locked packages remain third-party for source-containment purposes.

Lexical classification happens before canonical resolution. A declared
`packages/foo` symlink to a donor is therefore rejected as an escaped
first-party path package rather than reclassified as external.

The guard reads all three Composer compatibility arrays
(`autoload_psr4.php`, `autoload_classmap.php`, `autoload_files.php`) and the
static class that `autoload_real.php` names. It checks both planes
independently. This matters because Composer initializes PSR-4 and classmap
state from the static class and loads its static files array at runtime; a
healthy compatibility array cannot override donor-bound runtime state.

For each declared first-party PSR-4 entry, every mapped directory must resolve
inside the canonical candidate root. A generated descendant prefix inherits
the nearest declared first-party prefix, preventing a more-specific donor path
from shadowing an owned parent. A nearer prefix explicitly declared by a
third-party package retains third-party ownership. Optimized classmap entries under an owned
PSR-4 prefix and autoload-files entries identified by Composer's stable
`md5(package-name:path)` key have the same containment rule. Root and declared
source paths must also resolve inside the candidate. Candidate-internal
symlinks pass. Composer may dump a PSR-4 directory before that optional
directory exists, so a missing PSR-4 leaf is projected from its closest
existing canonical ancestor. It passes only when that ancestor remains inside
the candidate. A donor symlink ancestor resolves outside and a dangling
symlink cannot be verified; both fail. Raw components are preserved until the
filesystem resolves the existing ancestor: a generated `link/../missing` path
therefore follows the link target's parent and cannot be lexically collapsed
back into the candidate. Classmap and autoload-file targets still
must exist. Escapes and unresolved first-party paths fail with the map
plane, declaration or class/file identity, observed path, expected root, and a
candidate-local install repair. Third-party mappings are not rejected merely
for resolving elsewhere.

## Safety and recovery

The guard never follows the stale application autoloader and never invokes
the files listed in Composer's files map. Requiring a generated map evaluates
only its returned array or static class declaration. Composer 2.10 omits
`autoload_files.php` when no package declares files; that absence is accepted
only when neither the runtime static map nor an owned manifest requires files.
Generated files that do exist must themselves resolve under the candidate root,
and `autoload_real.php` must name the inspected static initializer.

When the candidate's whole `vendor/` is a symlink, the actionable repair is
`unlink vendor && composer install`: `unlink` removes only that candidate link.
For a nested metadata link the equivalent safe repair starts with
`unlink vendor/composer`; an ambiguous metadata escape requires restoring the
candidate-local directory before Composer runs.
Other mismatches prescribe `composer install`; an escaped declared path
package first requires restoring that candidate source path. No repair runs
automatically, and no donor path is modified.

## Evidence

- Dependency-free RED fixture: equal metadata plus a candidate-local
  compatibility map and donor-bound runtime-static PSR-4 map returned
  `MISSED_DONOR` before the change.
- The same fixture is GREEN after the change and reports the runtime plane,
  namespace, donor path, candidate root, and `composer install` repair.
- A dependency-free focused matrix passes twenty-three cases: runtime-static PSR-4,
  classmap, and autoload-files donor refusals; ordered compatibility PSR-4
  fallback refusal; candidate-local map acceptance; lexically internal
  path-package escape refusal; candidate-internal symlink acceptance; external
  declaration symlink refusal; whole-vendor donor-symlink refusal without
  donor mutation; external third-party mapping acceptance; descendant-prefix
  shadow refusal with an explicit third-party descendant positive; optional
  no-files-map acceptance with required-map refusal; nested metadata-link safe
  recovery; a missing candidate PSR-4 leaf positive; donor-ancestor and
  dangling-symlink missing-leaf negatives; symlink-plus-parent traversal
  refusal with an ordinary internal parent-traversal positive; and preserved missing-package, identity-mismatch, and
  missing-namespace refusals.
- A real Composer 2.10.2 scratch `dump-autoload` for a no-files project emitted
  PSR-4, classmap, real, and static files but no `autoload_files.php`, confirming
  the compatibility file is conditional.
- A real Composer 2.10.2 scratch `dump-autoload` preserved `Acme\\Missing\\ =>
  $baseDir/src` while `src/` did not exist, confirming that missing PSR-4 leaves
  are valid generated output.
- A read-only run against the physical, exact-qualified
  `fw-2660-client-skills` vendor returns fresh while preserving its legitimate
  missing `Waaseyaa\\CLI\\Io\\` directory mapping; no vendor bytes were changed.
- PHP syntax checks pass for the helper and PHPUnit fixture.
- `VendorFreshnessPreconditionTest` contains the durable fixture equivalents.
  Focused fixture tests exercise these controls; final exact-candidate
  qualification is retained separately.

## Scope boundary

This change does not edit CI, preflight orchestration, Composer manifests or
locks, application autoload behavior, or any candidate lane's `vendor/`. It
does not compare arbitrary third-party installation paths and does not claim
that matching dependency metadata proves first-party source identity without
the generated-map checks.
