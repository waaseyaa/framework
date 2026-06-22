# Framework Package Collapse — Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use `superpowers:executing-plans` to implement this plan task-by-task.

**Goal:** Execute [ADR-004](../adr/004-framework-package-collapse.md) — collapse `packages/framework` into the monorepo root, canonicalize `waaseyaa/framework` as the monorepo root composer.json, and adopt Symfony-style `replace` semantics.

> **Status 2026-04-16 — partial execution.** Phases 0, 3 (delete `packages/framework/`), and 4 (finalize `split.yml`) landed. Phases 1, 2, and the release tasks were deferred: mid-Phase-1 investigation found that `PackageManifestCompiler` and other framework discovery paths enumerate first-party packages via `vendor/composer/installed.json`, which `replace` semantics empty out. A follow-up ADR will fix discovery (synthesize installed entries from the root's replace block + path repos) before the root `composer.json` rewrite lands.

**Design doc:** `docs/adr/004-framework-package-collapse.md`

**Related tactical work (already landed 2026-04-16):**
- `.github/workflows/split.yml` — self-split guard, `verify-monorepo-main-intact` job, `packages/framework` matrix entry removed

---

## Phase Overview

| Phase | Focus | Tasks | Outcome |
|-------|-------|-------|---------|
| 0 | Preflight | 1-3 | Origin main restored; branch protection on; clean working tree |
| 1 | Inventory & drafting | 4-7 | Generated `replace`, `autoload.psr-4`, `require` unions in scratch files for review |
| 2 | Root composer.json rewrite | 8-11 | Root is canonical `waaseyaa/framework`, `type: library` with `replace` |
| 3 | Delete `packages/framework` | 12-14 | Subtree removed, monorepo root references cleared, CI matrix clean |
| 4 | Workflow + docs cleanup | 15-17 | split.yml final state, RELEASING.md written, README updated |
| 5 | Validation | 18-22 | Composer validates, dev install clean, consumer install clean, CI green |
| 6 | Release | 23-26 | Tag cut, split matrix succeeds, packagist updated, announced |

---

## Phase 0: Preflight

Before any code changes, the baseline must be sound. These tasks are owner-operated, not Claude-operated.

### Task 1: Restore `origin/main` to intact commit

**Owner:** Human (destructive push).

**Preconditions:**
- Local `main` at SHA that predates the 2026-04-16 split incident (expected: `9f9c77da8` or later release commit restored locally).
- `git ls-remote origin HEAD refs/heads/main` returns the skeleton SHA `18d84b30c...`.

**Steps:**

```bash
cd /home/jones/dev/waaseyaa
git fetch origin main                          # updates refs/remotes/origin/main
git log origin/main -1                         # confirm it's still the skeleton
git push --force-with-lease=main:18d84b30c8ff7a061fc1b3df80986f6f861be344 origin main:main
```

**Verify:**

```bash
gh api repos/waaseyaa/framework/contents/ --jq '.[].name' | wc -l   # expect >> 1
gh api repos/waaseyaa/framework/commits/main --jq '.sha'            # should match local main SHA
```

### Task 2: Add branch protection ruleset on `main`

**Owner:** Human (GitHub platform config).

**Steps:** Navigate to `github.com/waaseyaa/framework` → Settings → Rules → Rulesets → New branch ruleset. Apply to `main`:

- ☑ Restrict creations, updates, deletions
- ☑ Block force pushes
- ☑ Require pull request before merging (bypass: repo admins only, documented)
- ☐ Do NOT grant bypass to GitHub Actions

**Verify:**

```bash
gh api repos/waaseyaa/framework/rules/branches/main --jq '.[].type' | sort -u
# should include: non_fast_forward, deletion, pull_request
```

### Task 3: Rotate `SPLIT_GITHUB_TOKEN` to fine-grained PAT

**Owner:** Human (credential management).

**Steps:** Create a new fine-grained PAT scoped to specific repos — every `waaseyaa/*` split target listed in `.github/workflows/split.yml`, **explicitly excluding `waaseyaa/framework`**. Permissions: `contents: write` on listed repos only. Update repo secret `SPLIT_GITHUB_TOKEN`. Revoke the old token.

**Verify:** next tagged release run completes the split job successfully; monorepo-main-intact job passes.

---

## Phase 1: Inventory & Drafting

Produce three scratch files that the root composer.json rewrite will consume. Do not edit `composer.json` in this phase — only generate drafts for review.

### Task 4: Enumerate first-party package names

**Files created:**
- `/tmp/waaseyaa-collapse/package-names.txt`

**Steps:**

```bash
mkdir -p /tmp/waaseyaa-collapse
cd /home/jones/dev/waaseyaa
for f in packages/*/composer.json; do
    # skip the one being deleted
    [ "$(dirname "$f")" = "packages/framework" ] && continue
    jq -r '.name' "$f"
done | sort -u > /tmp/waaseyaa-collapse/package-names.txt
```

**Verify:**

```bash
wc -l /tmp/waaseyaa-collapse/package-names.txt   # expect ~60 packages
grep -c '^waaseyaa/' /tmp/waaseyaa-collapse/package-names.txt  # every line
```

### Task 5: Generate `replace` block draft

**Files created:**
- `/tmp/waaseyaa-collapse/replace-block.json`

**Steps:**

```bash
jq -R -s '
    split("\n")
    | map(select(length > 0))
    | map({(.): "self.version"})
    | add
' /tmp/waaseyaa-collapse/package-names.txt \
    > /tmp/waaseyaa-collapse/replace-block.json
```

**Verify:**

```bash
jq 'keys | length' /tmp/waaseyaa-collapse/replace-block.json
# must equal line count from Task 4
jq 'to_entries | .[] | select(.value != "self.version")' /tmp/waaseyaa-collapse/replace-block.json
# must output nothing
```

### Task 6: Generate `autoload.psr-4` union draft

**Files created:**
- `/tmp/waaseyaa-collapse/autoload-psr4.json`
- `/tmp/waaseyaa-collapse/autoload-conflicts.txt` (if any)

**Steps:** Walk every `packages/*/composer.json` `autoload.psr-4` entry. For each namespace prefix, rewrite the source path from package-relative (`src/`) to monorepo-relative (`packages/<name>/src/`). Detect conflicts (same prefix → different paths).

```bash
php -r '
$entries = [];
$conflicts = [];
foreach (glob("packages/*/composer.json") as $file) {
    if (dirname($file) === "packages/framework") continue;
    $json = json_decode(file_get_contents($file), true);
    $pkgDir = dirname($file);
    foreach ($json["autoload"]["psr-4"] ?? [] as $prefix => $paths) {
        $paths = (array) $paths;
        foreach ($paths as $p) {
            $full = $pkgDir . "/" . ltrim($p, "/");
            if (isset($entries[$prefix]) && $entries[$prefix] !== $full) {
                $conflicts[] = "$prefix: $full vs {$entries[$prefix]}";
            }
            $entries[$prefix] = $full;
        }
    }
}
ksort($entries);
file_put_contents("/tmp/waaseyaa-collapse/autoload-psr4.json", json_encode($entries, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
file_put_contents("/tmp/waaseyaa-collapse/autoload-conflicts.txt", implode("\n", $conflicts));
'
```

**Verify:**

```bash
wc -l /tmp/waaseyaa-collapse/autoload-conflicts.txt  # must be 0
jq 'keys | length' /tmp/waaseyaa-collapse/autoload-psr4.json  # sanity check, usually >= package count
```

**If conflicts exist:** stop. Inspect each conflict manually. A shared namespace prefix across two packages is a packaging bug that must be resolved before this migration proceeds.

### Task 7: Generate `require` union draft

**Files created:**
- `/tmp/waaseyaa-collapse/require-union.json`
- `/tmp/waaseyaa-collapse/require-conflicts.txt`

**Steps:** Walk every package `require`, take the *tightest* constraint per key. Conflict = same key with non-overlapping constraints (e.g., `^8.3` vs `^8.4`).

```bash
php -r '
require "vendor/autoload.php";
use Composer\Semver\VersionParser;
use Composer\Semver\Intervals;

$parser = new VersionParser();
$entries = [];
$conflicts = [];
foreach (glob("packages/*/composer.json") as $file) {
    if (dirname($file) === "packages/framework") continue;
    $json = json_decode(file_get_contents($file), true);
    foreach ($json["require"] ?? [] as $k => $v) {
        if (str_starts_with($k, "waaseyaa/")) continue;   // replaced by self
        if (!isset($entries[$k])) { $entries[$k] = $v; continue; }
        if ($entries[$k] === $v) continue;
        // record both for manual review; do not auto-merge
        $conflicts[] = "$k: already {$entries[$k]}, saw $v in $file";
    }
}
ksort($entries);
file_put_contents("/tmp/waaseyaa-collapse/require-union.json", json_encode($entries, JSON_PRETTY_PRINT));
file_put_contents("/tmp/waaseyaa-collapse/require-conflicts.txt", implode("\n", $conflicts));
'
```

**Verify:**

```bash
jq 'keys | length' /tmp/waaseyaa-collapse/require-union.json   # spot check
wc -l /tmp/waaseyaa-collapse/require-conflicts.txt             # review every line
```

**If conflicts exist:** resolve each by inspecting both packages. Pick the tighter constraint (or the correct one per runtime requirement). Update the corresponding package's composer.json to match before proceeding. Re-run Task 7 until clean.

---

## Phase 2: Root composer.json Rewrite

### Task 8: Back up current root composer.json

**Files created:**
- `composer.json.pre-collapse.backup`

**Steps:**

```bash
cp composer.json composer.json.pre-collapse.backup
```

**Verify:** file exists, matches `composer.json` byte-for-byte.

### Task 9: Rewrite root `composer.json`

**Files modified:**
- `composer.json`

**Steps:** Open `composer.json`. Apply in order:

1. Change `"type": "project"` → `"type": "library"`
2. Remove `"version": "1.1.0"` (packagist reads from tags; static version blocks branch aliases)
3. Add `"replace": {...}` block from `/tmp/waaseyaa-collapse/replace-block.json`
4. Add `"autoload": { "psr-4": {...} }` from `/tmp/waaseyaa-collapse/autoload-psr4.json`
5. Merge `"require": {...}` from `/tmp/waaseyaa-collapse/require-union.json` into existing `require`, preferring existing keys. Add `"php": "^8.4"` if not present.
6. Retain existing `"repositories": [...]` path entries — remove the one for `packages/framework` if present.
7. Retain existing `"scripts"`, `"config"`, `"autoload-dev"` sections.
8. Add a `"branch-alias"` entry under `"extra"` matching the component pattern: `"dev-main": "0.1.x-dev"` (or the current mainline alias; check `packages/foundation/composer.json` for the canonical value).

**Verify:**

```bash
composer validate --strict
jq '.name == "waaseyaa/framework"' composer.json          # true
jq '.type == "library"' composer.json                     # true
jq 'has("version") | not' composer.json                   # true (no static version)
jq '.replace | keys | length' composer.json               # matches Task 4 count
jq '.autoload."psr-4" | keys | length >= 50' composer.json # sanity
```

### Task 10: Confirm path repositories align with replace block

**Steps:** Every entry in `repositories[]` with `type: path` must have its declared package (from `packages/<name>/composer.json`) present in `replace`. No `repositories[]` entry for a package NOT in `replace`.

```bash
php -r '
$root = json_decode(file_get_contents("composer.json"), true);
$replaced = array_keys($root["replace"] ?? []);
foreach ($root["repositories"] ?? [] as $r) {
    if (($r["type"] ?? "") !== "path") continue;
    $pkgJson = json_decode(file_get_contents($r["url"] . "/composer.json"), true);
    $name = $pkgJson["name"] ?? null;
    if (!$name) continue;
    if (!in_array($name, $replaced, true)) {
        echo "MISSING from replace: $name (path: {$r["url"]})\n";
    }
}
'
```

**Verify:** no output.

### Task 11: Dev-install smoke test from monorepo root

**Steps:**

```bash
rm -rf vendor/ composer.lock
composer install --no-interaction 2>&1 | tail -20
```

**Verify:**
- Exit code 0
- `vendor/autoload.php` exists and loads
- `php -r 'require "vendor/autoload.php"; var_dump(class_exists("Waaseyaa\\Foundation\\Kernel\\ConsoleKernel"));'` → `bool(true)`
- `./bin/waaseyaa --version` boots (or `./vendor/bin/waaseyaa --version` — naming decided in parallel giiken cleanup)

---

## Phase 3: Delete `packages/framework`

### Task 12: Remove the subtree

**Files deleted:**
- `packages/framework/composer.json`
- `packages/framework/` directory (should be empty after)

**Steps:**

```bash
git rm -r packages/framework
ls packages/framework 2>&1 | grep -q 'No such'   # confirm gone
```

**Verify:**

```bash
test ! -d packages/framework
```

### Task 13: Remove `packages/framework` from monorepo dev config

**Steps:** If any file still references `packages/framework/`:

```bash
grep -rn 'packages/framework' --include='*.json' --include='*.yml' --include='*.yaml' --include='*.md' --include='*.php' \
    | grep -v 'docs/adr/004-framework-package-collapse.md' \
    | grep -v 'docs/history/plans/2026-04-16-framework-package-collapse-implementation.md'
```

**Verify:** no output (after the ADR + this plan are excluded).

Expected residual references to clean up proactively:
- Root `composer.json` path repositories (removed in Task 9 step 6)
- `.github/workflows/split.yml` (removed tactically 2026-04-16)
- Any README or docs listing meta-packages

### Task 14: Regenerate composer.lock

**Steps:**

```bash
composer update --lock
git add composer.lock
```

**Verify:**

```bash
jq '.packages | map(select(.name == "waaseyaa/framework")) | length == 0' composer.lock   # replaced, never installed separately
```

---

## Phase 4: Workflow + Docs Cleanup

### Task 15: Finalize `split.yml`

**Files modified:**
- `.github/workflows/split.yml`

**Steps:** Remove the interim comment block added 2026-04-16 explaining the removed `packages/framework` matrix entry — the migration eliminates the underlying condition. Retain:

- The "Guard against monorepo self-split" step (defense in depth)
- The `verify-monorepo-main-intact` job (defense in depth)
- All other existing matrix entries

**Verify:**

```bash
grep -c 'Guard against monorepo self-split' .github/workflows/split.yml   # == 1
grep -c 'verify-monorepo-main-intact' .github/workflows/split.yml         # >= 1
grep -c 'packages/framework' .github/workflows/split.yml                  # == 0
```

### Task 16: Write `RELEASING.md`

**Files created:**
- `RELEASING.md` (repo root)

**Content:** release cadence, tag policy, split matrix invariants (including: "a matrix entry's `remote` MUST NOT equal the monorepo's repo name"), how to add a new package to the split, incident playbook for a main-rewrite scenario, and a link to ADR-004.

**Verify:** file exists, contains the substring `MUST NOT equal the monorepo's repo name`.

### Task 17: Update README with new install surface

**Files modified:**
- `README.md`

**Steps:** In the installation section, document `composer require waaseyaa/framework` as the canonical install, noting that `replace` semantics mean individual components are bundled (cannot be version-overridden). Link to ADR-004 for the design rationale.

**Verify:** README contains `composer require waaseyaa/framework` in an install section.

---

## Phase 5: Validation

### Task 18: Full CI parity

**Steps:**

```bash
composer install --no-interaction
composer validate --strict
./vendor/bin/phpstan analyse --no-progress 2>&1 | tail -5
./vendor/bin/phpunit --testsuite=unit 2>&1 | tail -5
```

**Verify:** all four exit 0.

### Task 19: Consumer install smoke

**Steps:** In a scratch directory, require the monorepo via a path repo:

```bash
mkdir -p /tmp/waaseyaa-consumer-test && cd /tmp/waaseyaa-consumer-test
cat > composer.json <<EOF
{
    "minimum-stability": "dev",
    "repositories": [
        {"type": "path", "url": "/home/jones/dev/waaseyaa", "options": {"symlink": false}}
    ],
    "require": {
        "waaseyaa/framework": "dev-main"
    }
}
EOF
composer install --no-interaction
```

**Verify:**
- `vendor/waaseyaa/framework/` is the full monorepo tree
- `vendor/waaseyaa/access/` is **absent** (replaced — bundled inside framework)
- `vendor/waaseyaa/foundation/` is **absent**
- `php -r 'require "vendor/autoload.php"; var_dump(class_exists("Waaseyaa\\Access\\AccessPolicyInterface"));'` → `bool(true)`

### Task 20: Verify tag-based install resolves correctly

**Steps:** After a dev tag is cut locally, test install from that tag.

```bash
cd /home/jones/dev/waaseyaa && git tag v0.2.0-alpha.0-test
cd /tmp/waaseyaa-consumer-test && sed -i 's/dev-main/v0.2.0-alpha.0-test/' composer.json
rm -rf vendor composer.lock
composer install --no-interaction
```

**Verify:** resolves. Delete the test tag after:

```bash
cd /home/jones/dev/waaseyaa && git tag -d v0.2.0-alpha.0-test
```

### Task 21: Dispatch split.yml in dry-run or on a test tag

**Steps:** Push a throwaway tag `v0.2.0-alpha.0-dryrun` to a side branch to exercise the split pipeline without triggering a real release. Monitor:

```bash
gh run list --workflow=split.yml --limit 3
```

**Verify:**
- `split` matrix jobs all pass
- `verify-monorepo-main-intact` job passes
- Monorepo `origin/main` SHA unchanged

Delete the test tag from origin and local after.

### Task 22: Lint ADR references

**Steps:** Every doc that references `packages/framework` as an install target must be updated. Search:

```bash
grep -rn 'packages/framework' docs/ --include='*.md' 2>&1 | grep -v 'adr/004' | grep -v 'plans/2026-04-16'
```

**Verify:** no output.

---

## Phase 6: Release & Announce

### Task 23: Update CHANGELOG

**Files modified:**
- `CHANGELOG.md`

**Steps:** Add a `[Unreleased]` → `## [0.2.0-alpha.1] — YYYY-MM-DD` entry with a **BREAKING** header:

> **BREAKING (composer.json shape):** `waaseyaa/framework` is now published from the monorepo root as `type: library` with a `replace` block. The `packages/framework` meta-package has been removed. Consumers of `composer require waaseyaa/framework` continue to work transparently; individual component versions can no longer be overridden when the framework is installed. See ADR-004.

**Verify:** CHANGELOG has the entry; the BREAKING header is prominent.

### Task 24: Cut the release

**Owner:** Human (release decision).

**Steps:**

```bash
# pre-flight: working tree clean, main green
git tag v0.2.0-alpha.1 -m "Collapse packages/framework into monorepo root (ADR-004)"
git push origin main v0.2.0-alpha.1
```

**Verify:**
- `gh run list --event=push --limit 5` shows Release Pipeline, Split Monorepo, GitHub Release, and Sync Application Skeleton kicking off
- All succeed
- `verify-monorepo-main-intact` passes

### Task 25: Packagist reconciliation

**Steps:** Packagist entry for `waaseyaa/framework` already points at `github.com/waaseyaa/framework`. After the release tag lands, packagist auto-updates via webhook. Confirm:

```bash
curl -s https://packagist.org/packages/waaseyaa/framework.json | jq '.package.versions | keys | .[-5:]'
```

**Verify:** `v0.2.0-alpha.1` appears.

### Task 26: Announce

**Owner:** Human.

**Steps:** Post release notes — link to CHANGELOG BREAKING entry and ADR-004. If there are downstream consumer projects (e.g., `giiken`), note that they may want to bump their `waaseyaa/framework` constraint in the same PR that benefits from the `replace` semantics (deduplicated install tree).

---

## Rollback

If Phase 2-3 fails irrecoverably:

```bash
git restore --staged --worktree composer.json
cp composer.json.pre-collapse.backup composer.json
git restore --staged --worktree packages/framework  # undeletes
rm -rf vendor composer.lock
composer install
```

No rollback is available after Task 24 (tag push). Tags are immutable on packagist once consumed. A superseding release (`v0.2.0-alpha.2` with a revert) is the correct escape hatch.

---

## Risk Register

| Risk | Severity | Mitigation |
|---|---|---|
| PSR-4 namespace conflict discovered late (Task 6) | High | Blocks migration; Task 6 verify step halts early. Resolve by renaming one namespace in the conflicting package before proceeding. |
| `require` constraint conflict (Task 7) | Medium | Same halt pattern. Tightening constraints in the offending component composer.json is the fix. |
| `replace` breaks an unknown downstream consumer | Medium | The ADR documents the semantic change. Consumer only affected if they override a component version while also installing `waaseyaa/framework` — rare. |
| Packagist fails to pick up the release | Low | Manual re-sync via packagist UI. |
| Release pipeline regression from split.yml changes | Medium | Task 21 dry-run on a side tag before cutting the real release. |
| Monorepo main wiped again during release | Low (guards in place) | `verify-monorepo-main-intact` blocks `publish-github-release`; self-split guard rejects the offending matrix entry before push. |
