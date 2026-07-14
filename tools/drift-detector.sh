#!/usr/bin/env bash
#
# drift-detector.sh — Detect specs that drift from code, via PR-diff coupling.
#
# A spec is "stale" when a change set modifies contract-bearing SOURCE in a
# mapped package (packages/<pkg>/{src,app}/…, src/…, public/…) but does NOT
# also touch the mapped docs/specs/<name>.md in the SAME diff. This is the
# docs-as-code "update the docs and the code in the same PR" rule (cf.
# tools/check-changelog-discipline.sh) — a content/diff signal, not a git
# *timestamp* heuristic. Consequence: comment-only edits, README/.gitkeep/test/
# fixture changes, and base-branch commits never produce false positives, and a
# spec can no longer be "freshened" by an unrelated one-character edit.
#
# Usage: tools/drift-detector.sh [<base-ref>|<N>] [--output=json]
#   <base-ref>  Ref to diff against (default: origin/main; falls back to `main`
#               then HEAD~1 when unresolvable, e.g. a fresh clone / no remote).
#   <N>         Legacy: a bare positive integer is treated as base = HEAD~N.
#
# Override (acknowledge a flagged spec WITHOUT editing it — e.g. a comment-only
# or otherwise non-contract source change): put a line
#     spec-reviewed: <spec-path | spec-basename | all> [ — reason]
# in a commit message in the range. The blocking CI check intentionally reads
# commit history only, so acknowledgements remain attached to the revision they
# reviewed instead of depending on mutable PR metadata.
#
# Output modes:
#   --output=json / WAASEYAA_OUTPUT=json → wrapped via DriftDetectorFormatter
#   default                               → human STALE/OK report
#
# Exit codes: 0 = all coupled (or no source changes); 1 = one or more stale.

set -euo pipefail

# --- agent-output mode dispatch (must happen before any heavy work) ---
__DD_OUTPUT_MODE="human"
__DD_FILTERED_ARGS=()
for __arg in "$@"; do
    case "$__arg" in
        --output=json) __DD_OUTPUT_MODE="json" ;;
        *) __DD_FILTERED_ARGS+=("$__arg") ;;
    esac
done
if [[ "${WAASEYAA_OUTPUT:-}" == "json" ]]; then
    __DD_OUTPUT_MODE="json"
fi

if [[ "$__DD_OUTPUT_MODE" == "json" ]]; then
    # Re-exec self with the json flag stripped + env unset, capture stdout,
    # pipe through DriftDetectorFormatter::parseRawOutput() → format().
    set +e
    __DD_RAW="$(WAASEYAA_OUTPUT="" "$0" "${__DD_FILTERED_ARGS[@]}" 2>&1)"
    __DD_EXIT=$?
    set -e
    export __DD_RAW
    __DD_REPO_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
    export __DD_REPO_ROOT
    php -d display_errors=stderr -r '
require getenv("__DD_REPO_ROOT") . "/vendor/autoload.php";
$raw = (string) getenv("__DD_RAW");
$formatter = new Waaseyaa\AgentOutput\Formatter\DriftDetectorFormatter();
echo $formatter->format($formatter->parseRawOutput($raw));
'
    exit "$__DD_EXIT"
fi

REPO_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$REPO_ROOT"

# --- resolve the base ref to diff against ---
BASE_ARG="${__DD_FILTERED_ARGS[0]:-}"
if [[ -z "$BASE_ARG" ]]; then
  BASE_REF="origin/main"
elif [[ "$BASE_ARG" =~ ^[1-9][0-9]*$ ]]; then
  BASE_REF="HEAD~${BASE_ARG}"   # legacy "N commits" form
else
  BASE_REF="$BASE_ARG"
fi

if ! git rev-parse --verify --quiet "${BASE_REF}^{commit}" >/dev/null 2>&1; then
  if [[ "$BASE_REF" == "origin/main" ]] && git rev-parse --verify --quiet "main^{commit}" >/dev/null 2>&1; then
    BASE_REF="main"
  elif git rev-parse --verify --quiet "HEAD~1^{commit}" >/dev/null 2>&1; then
    echo "WARNING: base ref '${BASE_ARG:-origin/main}' unresolvable; falling back to HEAD~1." >&2
    BASE_REF="HEAD~1"
  else
    echo "No prior commit to diff against; skipping."
    exit 0
  fi
fi

# Three-dot: changes on HEAD since the merge-base with the base ref (the PR's
# own changes — immune to base-branch commits that a sliding HEAD~N window
# would otherwise drag in on a merge ref).
CHANGED_FILES="$(git diff --name-only "${BASE_REF}...HEAD" 2>/dev/null || true)"

if [ -z "$CHANGED_FILES" ]; then
  echo "No spec-affecting changes (no diff vs ${BASE_REF})."
  exit 0
fi

# Contract-bearing source only: production code under a package src/ or app/,
# the app src/, or public/. Excludes tests/testing/fixtures, *Test files, and
# non-source artifacts (.md/README, .gitkeep, *.json/*.lock manifests) — none of
# which define the contract a spec documents.
SOURCE_FILES="$(printf '%s\n' "$CHANGED_FILES" \
  | grep -E '^(packages/[^/]+/(src|app)/|src/|public/)' \
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
  ["packages/media/"]="docs/specs/entity-storage-two-axis.md"
  ["packages/messaging/"]="docs/specs/messaging.md"
  ["packages/migration/"]="docs/specs/migration-platform.md"
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
  ["packages/note/"]="docs/specs/ingestion-defaults.md"
  ["packages/node/"]="docs/specs/revision-system-unified.md"
  ["packages/relationship/"]="docs/specs/relationship-modeling.md"
  ["packages/ai-"]="docs/specs/ai-integration.md"
  ["packages/mcp/"]="docs/specs/mcp-endpoint.md"
  ["packages/seo/"]="docs/specs/seo.md"
  ["packages/user/"]="docs/specs/access-control.md"
  ["packages/ingestion/"]="docs/specs/ingestion-defaults.md"
  ["packages/auth/"]="docs/specs/access-control.md"
  ["packages/billing/"]="docs/specs/infrastructure.md"
  ["packages/github/"]="docs/specs/infrastructure.md"
  ["packages/deployer/"]="docs/specs/infrastructure.md"
  ["packages/inertia/"]="docs/specs/infrastructure.md"
  ["packages/frankenphp/"]="docs/specs/operations-playbooks.md"
  ["public/"]="docs/specs/middleware-pipeline.md"
)

declare -A AFFECTED_SPECS=()
declare -A SPEC_CHANGES=()
declare -A UNMAPPED_PKGS=()
__DD_MATCHED=0

record_spec() {
  local spec="$1" file="$2"
  AFFECTED_SPECS["$spec"]=1
  SPEC_CHANGES["$spec"]="${SPEC_CHANGES[$spec]:-}  $file"$'\n'
  __DD_MATCHED=1
}

# Emit (to stderr, advisory-only — never changes the exit code) a notice that
# contract-bearing source changed in package(s) absent from PATTERN_TO_SPEC and
# the secondary case map. Without this, an unmapped package's source change
# produces a silent pass — the coupling gate has a blind spot, not coverage.
warn_unmapped() {
  [ "${#UNMAPPED_PKGS[@]}" -eq 0 ] && return 0
  {
    echo "WARNING: contract-bearing source changed in package(s) not mapped to any spec:"
    for pkg in $(printf '%s\n' "${!UNMAPPED_PKGS[@]}" | sort); do
      echo "  - ${pkg} (no entry in PATTERN_TO_SPEC or the secondary case map)"
    done
    echo "  These changes were NOT coupling-checked. If the package has a spec,"
    echo "  add it to PATTERN_TO_SPEC in tools/drift-detector.sh so drift is caught."
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

  # Secondary mappings: files that affect additional specs
  case "$file" in
    packages/access/src/*FieldAccess*|packages/api/src/*Schema*)
      record_spec "docs/specs/field-access.md" "$file" ;;
    packages/foundation/src/Ingestion/*)
      record_spec "docs/specs/ingestion-defaults.md" "$file" ;;
    packages/foundation/src/*Provider*|packages/plugin/*)
      record_spec "docs/specs/package-discovery.md" "$file" ;;
    packages/*/src/Middleware/*)
      record_spec "docs/specs/middleware-pipeline.md" "$file" ;;
  esac

  # Track package source that matched no mapping at all, so the
  # "no specs mapped" outcome is reported instead of silently passing.
  if [ "$__DD_MATCHED" -eq 0 ] && [[ "$file" == packages/*/* ]]; then
    pkg_dir="${file%%/src/*}"
    pkg_dir="${pkg_dir%%/app/*}"
    UNMAPPED_PKGS["${pkg_dir}/"]=1
  fi
done <<< "$SOURCE_FILES"

if [ "${#AFFECTED_SPECS[@]}" -eq 0 ]; then
  echo "No specs mapped to the changed source."
  warn_unmapped
  exit 0
fi

# --- collect spec-reviewed acknowledgements from commit messages in range ---
declare -A ACK=()
ACK_RAW="$(git log --format=%B "${BASE_REF}..HEAD" 2>/dev/null || true)"
ACK_BACKTICK_PATTERN='^[[:space:]]*spec-reviewed:[[:space:]]*`([^`[:space:]]+)`([[:space:]]|$)'
ACK_PLAIN_PATTERN='^[[:space:]]*spec-reviewed:[[:space:]]*([^`[:space:]]+)'
while IFS= read -r line; do
  if [[ "$line" =~ $ACK_BACKTICK_PATTERN ]]; then
    tok="${BASH_REMATCH[1]}"
    ACK["$tok"]=1
    ACK["$(basename "$tok")"]=1
  elif [[ "$line" =~ $ACK_PLAIN_PATTERN ]]; then
    tok="${BASH_REMATCH[1]}"
    ACK["$tok"]=1
    ACK["$(basename "$tok")"]=1
  fi
done <<< "$ACK_RAW"

is_acknowledged() {
  local spec="$1"
  [ -n "${ACK[all]:-}" ] && return 0
  [ -n "${ACK[$spec]:-}" ] && return 0
  [ -n "${ACK[$(basename "$spec")]:-}" ] && return 0
  return 1
}

spec_in_diff() {
  printf '%s\n' "$CHANGED_FILES" | grep -qxF "$1"
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
  else
    echo "  STALE: $spec"
    echo "    Fix: update this spec in the same change set, or add"
    echo "         'spec-reviewed: $spec — <reason>' to a commit message"
    STALE_COUNT=$((STALE_COUNT + 1))
  fi
  echo "    Changed source:"
  echo -n "${SPEC_CHANGES[$spec]}" | sort -u | grep -v '^[[:space:]]*$' | head -10 | sed 's/^/    /'
done

warn_unmapped

echo ""
if [ $STALE_COUNT -gt 0 ]; then
  echo "$STALE_COUNT spec(s) need review. Update specs before merging."
  exit 1
else
  echo "All affected specs are up to date."
  exit 0
fi
