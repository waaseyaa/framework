#!/usr/bin/env bash
#
# drift-detector.sh — Detect specs that drift from code, via PR-diff coupling.
#
# A spec is "stale" when a change set modifies contract-bearing SOURCE in a
# mapped package (packages/<pkg>/{src,app,migrations}/…, src/…, public/…) but
# does NOT also touch the mapped docs/specs/<name>.md in the SAME diff. This is
# the docs-as-code "update the docs and the code in the same PR" rule (cf.
# tools/check-changelog-discipline.sh) — a content/diff signal, not a git
# *timestamp* heuristic. Consequence: comment-only edits, README/.gitkeep/test/
# fixture changes, and base-branch commits never produce false positives, and a
# spec can no longer be "freshened" by an unrelated one-character edit.
#
# This script cannot see a live spec whose stated dependency closed *elsewhere*
# (no diff ever touches that spec). That class is bin/check-stale-spec-deferrals,
# a nightly warn-only scan — not a preflight gate, and not invoked from here.
#
# Usage: tools/drift-detector.sh [--include-worktree] [<base-ref>|<N>]
#   --include-worktree  Include staged, unstaged, and untracked files. A commit
#                       acknowledgement never covers a later worktree change;
#                       the affected spec must also change in the worktree.
#   <base-ref>  Ref to diff against (default: origin/main, then `main`; an
#               EXPLICITLY supplied base that does not resolve is a hard
#               failure — there is no fallback).
#   <N>         Legacy: a bare positive integer is treated as base = HEAD~N.
#
# Override (acknowledge a flagged spec WITHOUT editing it — e.g. a comment-only
# or otherwise non-contract source change): put a line
#     spec-reviewed: <spec-path | spec-basename>[, <spec-path>...] [ - reason]
# in a commit message in the range. Tokens may be backtick-wrapped. An
# acknowledgement is provenance-ordered: it only covers source changes
# committed at or before the acknowledging commit, so an early trailer cannot
# pre-approve later edits — a final `git commit --allow-empty` carrying the
# trailer remains the supported way to acknowledge earlier changes. The blanket
# token `all` is retired and rejected. Tokens that are not spec paths, name no
# existing spec file, or name a spec unaffected by the diff are diagnosed on
# stderr and ignored. The blocking CI check intentionally reads commit history
# only, so acknowledgements remain attached to the revision they reviewed
# instead of depending on mutable PR metadata.
#
# Exit codes:
#   0 = all coupled (or no source changes)
#   1 = one or more stale specs / unmapped packages
#   2 = retired usage ('--output=json', 'spec-reviewed: all')
#   3 = explicitly supplied base ref does not resolve (fail closed, no fallback)
#   4 = no base ref determinable at all (single-commit/detached repository)
#   5 = internal git command failure (diff/log/rev-list) — never swallowed

set -euo pipefail

__DD_FILTERED_ARGS=()
__DD_INCLUDE_WORKTREE=0
for __arg in "$@"; do
    case "$__arg" in
        --include-worktree)
            __DD_INCLUDE_WORKTREE=1
            ;;
        --output=json)
            echo "drift-detector: --output=json is no longer supported; use the human gate output." >&2
            exit 2
            ;;
        *) __DD_FILTERED_ARGS+=("$__arg") ;;
    esac
done
if [[ "${WAASEYAA_OUTPUT:-}" == "json" ]]; then
    echo "drift-detector: --output=json is no longer supported; use the human gate output." >&2
    exit 2
fi

REPO_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$REPO_ROOT"

# --- resolve the base ref to diff against (fail closed) ---
BASE_ARG="${__DD_FILTERED_ARGS[0]:-}"
if [[ -n "$BASE_ARG" ]]; then
  if [[ "$BASE_ARG" =~ ^[1-9][0-9]*$ ]]; then
    BASE_REF="HEAD~${BASE_ARG}"   # legacy "N commits" form
  else
    BASE_REF="$BASE_ARG"
  fi
  if ! git rev-parse --verify --quiet "${BASE_REF}^{commit}" >/dev/null 2>&1; then
    echo "ERROR: base ref '${BASE_ARG}' does not resolve to a commit; refusing to fall back. Pass a valid base ref." >&2
    exit 3
  fi
else
  if git rev-parse --verify --quiet 'origin/main^{commit}' >/dev/null 2>&1; then
    BASE_REF="origin/main"
  elif git rev-parse --verify --quiet 'main^{commit}' >/dev/null 2>&1; then
    BASE_REF="main"
  elif git rev-parse --verify --quiet 'HEAD~1^{commit}' >/dev/null 2>&1; then
    echo "WARNING: neither origin/main nor main resolves; falling back to HEAD~1." >&2
    BASE_REF="HEAD~1"
  else
    echo "ERROR: no base ref available to diff against (single-commit or detached repository)." >&2
    echo "  The drift gate cannot verify spec coupling without a base; pass one explicitly: tools/drift-detector.sh <base-ref>" >&2
    exit 4
  fi
fi

# Three-dot: changes on HEAD since the merge-base with the base ref (the PR's
# own changes — immune to base-branch commits that a sliding HEAD~N window
# would otherwise drag in on a merge ref).
if ! CHANGED_FILES="$(git diff --name-only "${BASE_REF}...HEAD")"; then
  echo "ERROR: 'git diff --name-only ${BASE_REF}...HEAD' failed; cannot evaluate drift." >&2
  exit 5
fi

WORKTREE_FILES=""
if [ "$__DD_INCLUDE_WORKTREE" -eq 1 ]; then
  if ! __DD_TRACKED_WORKTREE="$(git diff --name-only HEAD)"; then
    echo "ERROR: 'git diff --name-only HEAD' failed; cannot evaluate worktree drift." >&2
    exit 5
  fi
  if ! __DD_UNTRACKED_WORKTREE="$(git ls-files --others --exclude-standard)"; then
    echo "ERROR: 'git ls-files --others --exclude-standard' failed; cannot evaluate worktree drift." >&2
    exit 5
  fi
  WORKTREE_FILES="$(printf '%s\n%s\n' "$__DD_TRACKED_WORKTREE" "$__DD_UNTRACKED_WORKTREE" | sed '/^$/d' | sort -u)"
  CHANGED_FILES="$(printf '%s\n%s\n' "$CHANGED_FILES" "$WORKTREE_FILES" | sed '/^$/d' | sort -u)"
fi

if [ -z "$CHANGED_FILES" ]; then
  echo "No spec-affecting changes (no diff vs ${BASE_REF})."
  exit 0
fi

# Contract-bearing source only: production code under a package src/ or app/,
# package migrations (schema is contract), the app src/, or public/. Excludes
# tests/testing/fixtures, *Test files, and non-source artifacts (.md/README,
# .gitkeep, *.json/*.lock manifests) — none of which define the contract a
# spec documents.
SOURCE_FILES="$(printf '%s\n' "$CHANGED_FILES" \
  | grep -E '^(packages/[^/]+/(src|app|migrations)/|src/|public/)' \
  | grep -vE '(^|/)(tests?|testing|Fixtures)/|(_test|Test)\.(php|ts)$|\.(md|json|lock|neon|layers)$|(^|/)\.gitkeep$' \
  || true)"

WORKTREE_SOURCE_FILES="$(printf '%s\n' "$WORKTREE_FILES" \
  | grep -E '^(packages/[^/]+/(src|app|migrations)/|src/|public/)' \
  | grep -vE '(^|/)(tests?|testing|Fixtures)/|(_test|Test)\.(php|ts)$|\.(md|json|lock|neon|layers)$|(^|/)\.gitkeep$' \
  || true)"

if [ -z "$SOURCE_FILES" ]; then
  echo "No contract-bearing source changes in diff vs ${BASE_REF}."
  exit 0
fi

echo "=== Drift Detector ==="
echo "Diffing against ${BASE_REF} (docs-as-code coupling check)..."
echo ""

# Mapping: directory pattern -> spec file
declare -A PATTERN_TO_SPEC=(
  ["packages/entity/"]="docs/specs/entity-system.md"
  ["packages/entity-storage/"]="docs/specs/entity-system.md"
  ["packages/field/"]="docs/specs/entity-system.md"
  ["packages/config/"]="docs/specs/entity-system.md"
  ["packages/access/"]="docs/specs/access-control.md"
  ["packages/audit/"]="docs/specs/ocap-audit-log.md"
  ["packages/attachment/"]="docs/specs/work-surface.md"
  ["packages/api/"]="docs/specs/api-layer.md"
  ["packages/graphql/"]="docs/specs/api-layer.md"
  ["packages/routing/"]="docs/specs/api-layer.md"
  ["packages/wayfinding/"]="docs/specs/wayfinding.md"
  ["packages/bimaaji/"]="docs/specs/bimaaji.md"
  ["packages/cli/"]="docs/specs/cli-kernel.md"
  ["packages/genealogy/"]="docs/specs/genealogy.md"
  ["packages/listing/"]="docs/specs/listing-pipeline-v1.md"
  ["packages/page-builder/"]="docs/specs/page-builder.md"
  ["packages/site-contract/"]="docs/specs/site-golden-path.md"
  ["packages/media/"]="docs/specs/entity-storage-two-axis.md"
  ["packages/messaging/"]="docs/specs/messaging.md"
  ["packages/migration/"]="docs/specs/migration-platform.md"
  ["packages/publishing/"]="docs/specs/content-publishing.md"
  ["packages/search/"]="docs/specs/search.md"
  ["packages/ssr/"]="docs/specs/app-controller-invocation.md"
  ["packages/workspace/"]="docs/specs/workspace-chat-surface.md"
  ["packages/workflows/"]="docs/specs/content-workflow.md"
  ["packages/foundation/"]="docs/specs/infrastructure.md"
  ["packages/cache/"]="docs/specs/infrastructure.md"
  ["packages/debug/"]="docs/specs/debugging-dx.md"
  ["packages/database-legacy/"]="docs/specs/infrastructure.md"
  ["packages/error-handler/"]="docs/specs/debugging-dx.md"
  ["packages/plugin/"]="docs/specs/infrastructure.md"
  ["packages/i18n/"]="docs/specs/infrastructure.md"
  ["packages/queue/"]="docs/specs/infrastructure.md"
  ["packages/scheduler/"]="docs/specs/infrastructure.md"
  ["packages/state/"]="docs/specs/infrastructure.md"
  ["packages/validation/"]="docs/specs/infrastructure.md"
  ["packages/typed-data/"]="docs/specs/infrastructure.md"
  ["packages/testing/"]="docs/specs/infrastructure.md"
  ["packages/mail/"]="docs/specs/infrastructure.md"
  ["packages/http-client/"]="docs/specs/infrastructure.md"
  ["packages/admin/"]="docs/specs/admin-spa.md"
  ["packages/admin-surface/"]="docs/specs/admin-spa.md"
  ["packages/note/"]="docs/specs/ingestion-defaults.md"
  ["packages/node/"]="docs/specs/revision-system-unified.md"
  ["packages/oidc/"]="docs/specs/api-layer.md"
  ["packages/relationship/"]="docs/specs/relationship-modeling.md"
  ["packages/ai-"]="docs/specs/ai-integration.md"
  ["packages/mcp/"]="docs/specs/mcp-endpoint.md"
  ["packages/oidc/"]="docs/specs/api-layer.md"
  ["packages/seo/"]="docs/specs/seo.md"
  ["packages/user/"]="docs/specs/access-control.md"
  ["packages/ingestion/"]="docs/specs/ingestion-defaults.md"
  ["packages/auth/"]="docs/specs/access-control.md"
  ["packages/billing/"]="docs/specs/infrastructure.md"
  ["packages/github/"]="docs/specs/infrastructure.md"
  ["packages/deployer/"]="docs/specs/infrastructure.md"
  ["packages/inertia/"]="docs/specs/infrastructure.md"
  ["packages/frankenphp/"]="docs/specs/operations-playbooks.md"
  ["packages/analytics/"]="packages/analytics/README.md"
  ["packages/engagement/"]="packages/engagement/README.md"
  ["packages/geo/"]="packages/geo/README.md"
  ["packages/groups/"]="docs/specs/bundle-scoped-storage.md"
  ["packages/menu/"]="docs/specs/entity-system.md"
  ["packages/mercure/"]="packages/mercure/README.md"
  ["packages/notification/"]="docs/specs/infrastructure.md"
  ["packages/oauth-provider/"]="packages/oauth-provider/README.md"
  ["packages/path/"]="docs/specs/api-layer.md"
  ["packages/structured-import/"]="docs/specs/work-surface.md"
  ["packages/taxonomy/"]="docs/specs/entity-system.md"
  ["public/"]="docs/specs/middleware-pipeline.md"
)

declare -A AFFECTED_SPECS=()
declare -A SPEC_CHANGES=()
declare -A FILE_SPECS=()
declare -A UNMAPPED_PKGS=()
declare -A WORKTREE_AFFECTED_SPECS=()
__DD_MATCHED=0

record_spec() {
  local spec="$1" file="$2"
  AFFECTED_SPECS["$spec"]=1
  SPEC_CHANGES["$spec"]="${SPEC_CHANGES[$spec]:-}  $file"$'\n'
  FILE_SPECS["$file"]="${FILE_SPECS[$file]:-} $spec"
  __DD_MATCHED=1
}

# Emit (to stderr, advisory-only — never changes the exit code) a notice that
# contract-bearing source changed in package(s) absent from PATTERN_TO_SPEC and
# the secondary case map. Without this, an unmapped package's source change
# produces a silent pass — the coupling gate has a blind spot, not coverage.
warn_unmapped() {
  [ "${#UNMAPPED_PKGS[@]}" -eq 0 ] && return 0
  {
    echo "BLOCKED: contract-bearing source changed in package(s) not mapped to any spec:"
    for pkg in $(printf '%s\n' "${!UNMAPPED_PKGS[@]}" | sort); do
      echo "  - ${pkg} (no entry in PATTERN_TO_SPEC or the secondary case map)"
    done
    echo "  These changes were NOT coupling-checked. Map every package before merging."
  } >&2
}

while IFS= read -r file; do
  [ -z "$file" ] && continue

  __DD_MATCHED=0
  for pattern in "${!PATTERN_TO_SPEC[@]}"; do
    if [[ "$file" == "$pattern"* ]]; then
      record_spec "${PATTERN_TO_SPEC[$pattern]}" "$file"
    fi
  done

  # Secondary mappings: files that affect additional specs. Arms fall through
  # (`;;&`) — one file may legitimately couple to several specs.
  case "$file" in
    packages/access/src/*FieldAccess*|packages/api/src/*Schema*)
      record_spec "docs/specs/field-access.md" "$file" ;;&
    packages/foundation/src/Ingestion/*)
      record_spec "docs/specs/ingestion-defaults.md" "$file" ;;&
    packages/foundation/src/*Provider*|packages/plugin/*)
      record_spec "docs/specs/package-discovery.md" "$file" ;;&
    packages/*/src/Middleware/*)
      record_spec "docs/specs/middleware-pipeline.md" "$file" ;;&
    packages/oidc/src/Jwks/*|packages/oidc/src/Key/*|packages/oidc/src/Keys/*|packages/oidc/src/Token/IdTokenMinter.php|packages/oidc/src/Token/*KeyMaterialProvider*|packages/oidc/src/Token/TokenCustodySequenceAllocator.php|packages/oidc/migrations/*|packages/cli/src/Command/Oidc/*|packages/config/src/Manifest/*)
      record_spec "docs/specs/s1-signing-key-lifecycle.md" "$file" ;;&
    packages/oidc/src/Security/*|packages/oidc/src/Rekey/*|packages/config/src/Schema/Ai/*|packages/cli/src/AdminBuild/*|packages/cli/src/Handler/AdminBuildHandler.php)
      record_spec "docs/specs/security-defaults.md" "$file" ;;&
    packages/config/src/Manifest/*)
      record_spec "docs/specs/config-management.md" "$file" ;;&
    packages/config/src/Schema/Ai/*)
      record_spec "docs/specs/agent-executor.md" "$file" ;;&
    packages/cli/src/AdminBuild/*|packages/cli/src/Handler/AdminBuildHandler.php)
      record_spec "docs/specs/admin-spa.md" "$file" ;;&
  esac

  # Track package source that matched no mapping at all, so the
  # "no specs mapped" outcome is reported instead of silently passing.
  if [ "$__DD_MATCHED" -eq 0 ] && [[ "$file" == packages/*/* ]]; then
    pkg_dir="${file%%/src/*}"
    pkg_dir="${pkg_dir%%/app/*}"
    pkg_dir="${pkg_dir%%/migrations/*}"
    UNMAPPED_PKGS["${pkg_dir}/"]=1
  fi
done <<< "$SOURCE_FILES"

# A commit trailer or an earlier committed spec edit cannot pre-approve a
# later worktree source change. Record which specs need a same-worktree edit.
while IFS= read -r file; do
  [ -z "$file" ] && continue
  for spec in ${FILE_SPECS[$file]:-}; do
    WORKTREE_AFFECTED_SPECS["$spec"]=1
  done
done <<< "$WORKTREE_SOURCE_FILES"

if [ "${#AFFECTED_SPECS[@]}" -eq 0 ]; then
  echo "No specs mapped to the changed source."
  warn_unmapped
  [ "${#UNMAPPED_PKGS[@]}" -eq 0 ] && exit 0
  echo "${#UNMAPPED_PKGS[@]} unmapped package(s) block specification-drift verification."
  exit 1
fi

# --- collect spec-reviewed acknowledgements, provenance-ordered ---
#
# Walk the range oldest-first assigning each commit a topological index. A
# spec's LAST_SRC index is the last commit that touched mapped source for it;
# an acknowledgement is effective only from a commit at or after that index.
declare -A LAST_SRC=()
declare -A LAST_SRC_SHA=()
declare -A ACK_IDX=()
declare -A ACK_SHA=()
declare -A __DD_WARNED=()

warn_once() {
  local key="$1" msg="$2"
  [ -n "${__DD_WARNED[$key]:-}" ] && return 0
  __DD_WARNED["$key"]=1
  echo "$msg" >&2
}

if ! RANGE_COMMITS="$(git rev-list --reverse --topo-order "${BASE_REF}..HEAD")"; then
  echo "ERROR: 'git rev-list ${BASE_REF}..HEAD' failed; cannot evaluate acknowledgements." >&2
  exit 5
fi

__DD_IDX=0
while IFS= read -r c; do
  [ -z "$c" ] && continue

  # Ordinary commits are attributed from their parent diff. Merge commits use
  # a combined diff so only resolution changes unique to the merge are
  # attributed here; changes already carried by a parent were attributed to
  # their original commits. This is essential on GitHub's synthetic PR merge
  # checkout, where diffing the merge against its first parent would replay the
  # entire PR after the head commit's acknowledgement.
  if git rev-parse --verify --quiet "${c}^2" >/dev/null 2>&1; then
    if ! COMMIT_FILES="$(git diff-tree -r --cc --name-only --no-commit-id "$c")"; then
      echo "ERROR: 'git diff-tree --cc' failed for merge commit ${c}." >&2
      exit 5
    fi
  elif git rev-parse --verify --quiet "${c}^1" >/dev/null 2>&1; then
    if ! COMMIT_FILES="$(git diff-tree -r --name-only --no-commit-id "${c}^1" "$c")"; then
      echo "ERROR: 'git diff-tree' failed for commit ${c}." >&2
      exit 5
    fi
  else
    if ! COMMIT_FILES="$(git diff-tree -r --root --name-only --no-commit-id "$c")"; then
      echo "ERROR: 'git diff-tree' failed for commit ${c}." >&2
      exit 5
    fi
  fi
  while IFS= read -r f; do
    [ -z "$f" ] && continue
    [ -n "${FILE_SPECS[$f]:-}" ] || continue
    for spec in ${FILE_SPECS[$f]}; do
      LAST_SRC["$spec"]=$__DD_IDX
      LAST_SRC_SHA["$spec"]="$c"
    done
  done <<< "$COMMIT_FILES"

  if ! COMMIT_MSG="$(git log -1 --format=%B "$c")"; then
    echo "ERROR: 'git log -1 ${c}' failed; cannot read acknowledgements." >&2
    exit 5
  fi
  while IFS= read -r line; do
    [[ "$line" =~ ^[[:space:]]*spec-reviewed:[[:space:]]*(.+)$ ]] || continue
    payload="${BASH_REMATCH[1]}"
    IFS=',' read -ra __dd_segments <<< "$payload"
    for segment in "${__dd_segments[@]}"; do
      segment="${segment#"${segment%%[![:space:]]*}"}"
      segment="${segment%"${segment##*[![:space:]]}"}"
      [ -z "$segment" ] && continue
      tok="${segment%%[[:space:]]*}"
      tok="${tok#\`}"
      tok="${tok%\`}"
      [ -z "$tok" ] && continue
      if [[ "$tok" == "all" ]]; then
        {
          echo "ERROR: 'spec-reviewed: all' is no longer accepted (a blanket acknowledgement defeats the coupling gate)."
          echo "  Found in commit ${c}."
          echo "  List each affected spec explicitly, e.g.:"
          echo "      spec-reviewed: docs/specs/entity-system.md, docs/specs/api-layer.md - <reason>"
        } >&2
        exit 2
      fi
      if [[ "$tok" != *.md ]]; then
        warn_once "grammar:$tok" "WARNING: spec-reviewed token '${tok}' (commit ${c}) is not a spec path; ignored. Grammar: spec-reviewed: docs/specs/<name>.md[, docs/specs/<other>.md] - <reason>"
        continue
      fi
      resolved="$tok"
      [[ "$tok" != */* ]] && resolved="docs/specs/${tok}"
      if [ ! -f "$REPO_ROOT/$resolved" ]; then
        warn_once "missing:$tok" "WARNING: spec-reviewed token '${tok}' (commit ${c}) names no spec file in the repository (${resolved} not found); ignored."
        continue
      fi
      if [ -z "${AFFECTED_SPECS[$resolved]:-}" ]; then
        warn_once "unaffected:$resolved" "WARNING: spec-reviewed acknowledgement for '${resolved}' is not affected by this change set; ignored."
        continue
      fi
      ACK_IDX["$resolved"]=$__DD_IDX
      ACK_SHA["$resolved"]="$c"
    done
  done <<< "$COMMIT_MSG"
  __DD_IDX=$((__DD_IDX + 1))
done <<< "$RANGE_COMMITS"

is_acknowledged() {
  local spec="$1"
  [ -n "${WORKTREE_AFFECTED_SPECS[$spec]:-}" ] && return 1
  local ack_idx="${ACK_IDX[$spec]:--1}"
  [ "$ack_idx" -lt 0 ] && return 1
  [ "$ack_idx" -ge "${LAST_SRC[$spec]:-0}" ]
}

spec_in_diff() {
  local spec="$1"
  if [ -n "${WORKTREE_AFFECTED_SPECS[$spec]:-}" ]; then
    printf '%s\n' "$WORKTREE_FILES" | grep -qxF "$spec"
    return
  fi
  printf '%s\n' "$CHANGED_FILES" | grep -qxF "$spec"
}

echo "Affected specs:"
echo ""

STALE_COUNT=0
for spec in $(printf '%s\n' "${!AFFECTED_SPECS[@]}" | sort); do
  if [ ! -f "$REPO_ROOT/$spec" ]; then
    echo "  MISSING: $spec"
    echo "    Fix: Create this spec file to document the package"
    STALE_COUNT=$((STALE_COUNT + 1))
  elif spec_in_diff "$spec"; then
    echo "  OK: $spec (updated in this change set)"
  elif is_acknowledged "$spec"; then
    echo "  OK: $spec (acknowledged via 'spec-reviewed:')"
  elif [ -n "${WORKTREE_AFFECTED_SPECS[$spec]:-}" ]; then
    echo "  STALE: $spec"
    echo "    Uncommitted source changes are not covered by committed spec edits or 'spec-reviewed:' trailers."
    echo "    Fix: update this spec in the worktree before committing"
    STALE_COUNT=$((STALE_COUNT + 1))
  elif [ -n "${ACK_IDX[$spec]:-}" ]; then
    echo "  STALE: $spec"
    echo "    Acknowledgement in ${ACK_SHA[$spec]} predates later source change in ${LAST_SRC_SHA[$spec]:-unknown}."
    echo "    Fix: re-review, then add a fresh 'spec-reviewed: $spec - <reason>'"
    echo "         trailer (a final --allow-empty commit works), or update the spec"
    STALE_COUNT=$((STALE_COUNT + 1))
  else
    echo "  STALE: $spec"
    echo "    Fix: update this spec in the same change set, or add"
    echo "         'spec-reviewed: $spec - <reason>' to a commit message"
    STALE_COUNT=$((STALE_COUNT + 1))
  fi
  echo "    Changed source:"
  echo -n "${SPEC_CHANGES[$spec]}" | sort -u | grep -v '^[[:space:]]*$' | head -10 | sed 's/^/    /'
done

warn_unmapped
STALE_COUNT=$((STALE_COUNT + ${#UNMAPPED_PKGS[@]}))

echo ""
if [ $STALE_COUNT -gt 0 ]; then
  echo "$STALE_COUNT spec(s) need review. Update specs before merging."
  exit 1
else
  echo "All affected specs are up to date."
  exit 0
fi
