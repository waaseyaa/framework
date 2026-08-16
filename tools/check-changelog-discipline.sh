#!/usr/bin/env bash
#
# Changelog discipline check.
#
# Enforces docs/specs/stability-charter.md §8.2. Run by
# .github/workflows/changelog-discipline.yml.
#
# Strategy:
#   1. Compute changed files between the PR HEAD and the merge-base with the
#      target branch (default: origin/main).
#   2. If any changed file is a public-surface file (packages/*/src/, src/,
#      docs/public-surface-map.*), require that the PR also touched
#      CHANGELOG.md or a file under docs/upgrades/.
#   3. Maintainers may override by including "no-changelog: <reason>" in the
#      PR body (passed via $PR_BODY environment variable).
#
# Exit codes:
#   0 — discipline satisfied, override accepted, or no public-surface changes
#   1 — discipline violated
#
# Usage:
#   bash tools/check-changelog-discipline.sh [--include-worktree] [<base-ref>]

set -euo pipefail

base="origin/main"
base_set=0
include_worktree=0
for argument in "$@"; do
    case "$argument" in
        --include-worktree) include_worktree=1 ;;
        -*)
            echo "changelog-discipline: unknown option '${argument}'." >&2
            exit 2
            ;;
        *)
            if [[ "$base_set" -eq 1 ]]; then
                echo "changelog-discipline: more than one base ref supplied." >&2
                exit 2
            fi
            base="$argument"
            base_set=1
            ;;
    esac
done

# Ensure base is fetched.
git rev-parse --verify "${base}" >/dev/null 2>&1 || {
    echo "changelog-discipline: cannot resolve base ref '${base}'." >&2
    exit 2
}

changed="$(git diff --name-only "${base}...HEAD")"
if [[ "$include_worktree" -eq 1 ]]; then
    tracked_worktree="$(git diff --name-only HEAD)"
    untracked_worktree="$(git ls-files --others --exclude-standard)"
    changed="$(printf '%s\n%s\n%s\n' "$changed" "$tracked_worktree" "$untracked_worktree" | sed '/^$/d' | sort -u)"
fi

if [[ -z "${changed}" ]]; then
    echo "changelog-discipline: no changes detected; skipping."
    exit 0
fi

# Heuristic for public-surface files. Refine as new surface locations are added.
surface_changed="$(printf '%s\n' "${changed}" | grep -E '^(packages/[^/]+/src/|src/|docs/public-surface-map\.)' || true)"

if [[ -z "${surface_changed}" ]]; then
    echo "changelog-discipline: no public-surface files changed; skipping."
    exit 0
fi

# Check for changelog or upgrade-guide updates.
changelog_changed="$(printf '%s\n' "${changed}" | grep -E '^(CHANGELOG\.md|docs/upgrades/)' || true)"

if [[ -n "${changelog_changed}" ]]; then
    echo "changelog-discipline: passed (changelog or upgrade guide updated)."
    exit 0
fi

# Check for maintainer override marker in PR body.
if [[ -n "${PR_BODY:-}" ]] && printf '%s' "${PR_BODY}" | grep -qE '^no-changelog:[[:space:]]*[^[:space:]]'; then
    echo "changelog-discipline: maintainer override accepted ('no-changelog:' marker found in PR body)."
    exit 0
fi

# Fail with diagnostic output.
cat >&2 <<EOF
changelog-discipline: FAIL

PR modifies public-surface files but does NOT update CHANGELOG.md or docs/upgrades/.

Public-surface files changed:
$(printf '  %s\n' ${surface_changed})

To fix:
  - Add an entry to CHANGELOG.md under \`### Added\`, \`### Changed\`, \`### Deprecated\`,
    \`### Removed\`, or \`### Fixed\` (Keep-a-Changelog format).
  - OR add/update a file under docs/upgrades/.
  - OR include 'no-changelog: <reason>' in the PR description (maintainer override).

See docs/specs/stability-charter.md §8.2 for the policy.
EOF

exit 1
