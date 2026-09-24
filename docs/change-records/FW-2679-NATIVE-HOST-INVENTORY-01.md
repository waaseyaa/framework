# Native-host `bin/` inventory reconciliation

Status: implementation candidate, closing slice of Framework #2679 (program
#2676). Base: `b21e3630964872f59b15de452b55f0f020a3cd4e`. The read-only #2679
closure audit on this base found two confirmed blockers, both addressed here.

## Problem

1. `docs/specs/native-host-support.md` states that `bin/` contains 93
   Git-tracked top-level entries, each appearing exactly once in its partition.
   The base tracks 103 top-level files. The Bash (29), Node (1) and PowerShell
   (1) rows were exact. Ten PHP files had no disposition at all:
   - the CI and governance tools `audit-ci-roster-live`,
     `check-ci-roster-conformance`, `classify-ci-run-evidence`,
     `collect-ci-run-evidence`, `generate-ci-workflow-inventory`,
     `project-ci-ruleset` and `report-ci-measurement`;
   - `check-package-coverage-history`, `maintainer-skills` and
     `project-hooks-launcher`.

   Nothing enforced the promise, so the partition drifted unnoticed as tools
   were added.
2. The consumer-facing `skeleton/CLAUDE.md` listed
   `bin/maintenance/waaseyaa-audit-site` (POSIX-only Bash) and
   `bin/maintenance/waaseyaa-version` (a PHP file started through its shebang,
   which native Windows cannot execute) as ordinary development commands.
   Neither carried a host label, while `skeleton/README.md` already marks
   `audit-site` POSIX-only.

## Contract

- The partition describes 103 Git-tracked top-level files. The `bin/lib/`
  helper directory is not an entry.
- The ten files join the "Host-specific internal framework-maintainer tools
  with no native Windows support claim" row, which grows from 57 to 67 PHP
  entries.
- `project-hooks-launcher` is noted as the internal Composer entrypoint for
  `hooks:install` and `hooks:doctor`, already described in the Composer-script
  row.
- No disposition of any other entry changes, and no support claim is added.
- `tests/Architecture/NativeHostBinInventoryTest.php` enumerates the tracked
  top-level `bin/` files through the read-only `repositoryGit()` helper
  (`git ls-files`). It fails on any file missing from the partition, listed
  twice, or not tracked, and on a stated total or row count that does not
  match.
- `skeleton/CLAUDE.md` gives the provenance command as
  `php bin/maintenance/waaseyaa-version`. It marks `waaseyaa-audit-site` as
  optional, POSIX-only and outside the portable workflow on native Windows,
  where the portable verification command is `composer site-verify`.

## Out of scope

Runtime code, Composer scripts, raw `bin/git` convergence, the native
`ProjectHooksTest` harness failures, and the #2678 native Windows CI wiring.

## Verification boundary

- **Base, native Windows:** the guard fails. It reports exactly the ten
  unlisted files, and the stated total (93) against the tracked count (103).
- **Candidate:** the guard passes on native Windows and on Linux.
- **Rejection paths:** temporary spec mutations show the guard catching a
  duplicated entry, a listed-but-untracked name, and a wrong row count.
- **Linux boundary:** the Linux run used a WSL2-native clone of the exact
  candidate commit, with its own dependency install, because `repositoryGit()`
  deliberately clears `GIT_DIR` and WSL's Git cannot follow the Windows
  worktree's `gitdir:` pointer. It is local Linux evidence; hosted Linux CI
  qualification of the exact candidate is still required.
