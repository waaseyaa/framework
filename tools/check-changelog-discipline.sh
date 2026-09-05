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
#   2. If any changed file is a public-surface file (packages/*/src/,
#      packages/*/public-surface.php, src/, docs/public-surface-map.*),
#      require that the PR also touched
#      a valid file under changes/unreleased/ or a file under docs/upgrades/.
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
statuses="$(git diff --name-status "${base}...HEAD")"
merge_base="$(git merge-base "${base}" HEAD)"
if [[ "$include_worktree" -eq 1 ]]; then
    tracked_worktree="$(git diff --name-only HEAD)"
    untracked_worktree="$(git ls-files --others --exclude-standard)"
    changed="$(printf '%s\n%s\n%s\n' "$changed" "$tracked_worktree" "$untracked_worktree" | sed '/^$/d' | sort -u)"
    worktree_statuses="$(git diff --name-status HEAD)"
    untracked_statuses="$(printf '%s\n' "$untracked_worktree" | sed '/^$/d;s/^/A\t/')"
    statuses="$(printf '%s\n%s\n%s\n' "$statuses" "$worktree_statuses" "$untracked_statuses" | sed '/^$/d')"
fi

if [[ -z "${changed}" ]]; then
    echo "changelog-discipline: no changes detected; skipping."
    exit 0
fi

# The root changelog is release output, not an ordinary PR input. Historical
# maintenance remains possible, but requires a separate explicit marker; the
# existing no-changelog marker never authorizes central-file mutation.
if printf '%s\n' "${changed}" | grep -qx 'CHANGELOG.md'; then
    if [[ -z "${PR_BODY:-}" ]] || ! printf '%s' "${PR_BODY}" | grep -qE '^changelog-maintenance:[[:space:]]*[^[:space:]]'; then
        echo "changelog-discipline: CHANGELOG.md is release-owned; add a fragment or use 'changelog-maintenance: <reason>'." >&2
        exit 1
    fi
fi

# Pending fragments are append-only between releases. Editing or deleting a
# fragment already present on the base could silently rewrite another PR's
# release note or remove it from the eventual cut.
invalid_fragment_status=""
while IFS=$'\t' read -r status first_path second_path; do
    [[ -z "${status}" ]] && continue
    for fragment_path in "${first_path:-}" "${second_path:-}"; do
        [[ "${fragment_path}" =~ ^changes/unreleased/[^/]+\.md$ ]] || continue
        # A fragment absent from the merge base belongs to this PR and may be
        # amended before merge. Only a path already pending on the base is
        # immutable across an ordinary PR.
        if git cat-file -e "${merge_base}:${fragment_path}" 2>/dev/null; then
            invalid_fragment_status+="${status}"$'\t'"${fragment_path}"$'\n'
        fi
    done
done <<< "${statuses}"
invalid_fragment_status="${invalid_fragment_status%$'\n'}"
if [[ -n "${invalid_fragment_status}" ]]; then
    echo "changelog-discipline: pending fragments are append-only; found non-addition change:" >&2
    printf '  %s\n' ${invalid_fragment_status} >&2
    exit 1
fi

fragment_changed="$(printf '%s\n' "${changed}" | grep -E '^changes/unreleased/[^/]+\.md$' || true)"
if [[ -n "${fragment_changed}" ]]; then
    php bin/changelog-fragments validate
fi

# Heuristic for public-surface files. Refine as new surface locations are added.
surface_changed="$(printf '%s\n' "${changed}" | grep -E '^(packages/[^/]+/src/|packages/[^/]+/public-surface\.php|src/|docs/public-surface-map\.)' || true)"

if [[ -z "${surface_changed}" ]]; then
    echo "changelog-discipline: no public-surface files changed; skipping."
    exit 0
fi

# Check for a fragment or upgrade-guide update.
changelog_changed="$(printf '%s\n' "${changed}" | grep -E '^(changes/unreleased/[^/]+\.md|docs/upgrades/)' || true)"

if [[ -n "${changelog_changed}" ]]; then
    echo "changelog-discipline: passed (fragment or upgrade guide updated)."
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

PR modifies public-surface files but does NOT add a changelog fragment or update docs/upgrades/.

Public-surface files changed:
$(printf '  %s\n' ${surface_changed})

To fix:
  - Add a validated \`changes/unreleased/<issue>.<unique-slug>.<type>.md\` fragment.
  - OR add/update a file under docs/upgrades/.
  - OR include 'no-changelog: <reason>' in the PR description (maintainer override).

See docs/specs/stability-charter.md §8.2 for the policy.
EOF

exit 1
