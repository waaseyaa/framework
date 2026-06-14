#!/usr/bin/env bash
#
# drift-detector.sh — Detect stale specs by comparing git timestamps.
#
# Usage: tools/drift-detector.sh [N] [--output=json]
#   N = number of recent commits to check (default: 5)
#
# Output modes (M4 WP04C of mission `agent-output-package-01KS5VX1`):
#
#   --output=json    NDJSON envelope on stdout via Waaseyaa\AgentOutput\Formatter\DriftDetectorFormatter
#   WAASEYAA_OUTPUT=json    same effect via env var
#
# Default behaviour (no flag, no env var) is unchanged: human-readable
# stale/OK report, exit 0/1.
#
# Exit codes:
#   0 = all specs up to date (or no specs affected)
#   1 = one or more specs are stale or missing

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

N="${1:-5}"

# Validate input
if ! [[ "$N" =~ ^[1-9][0-9]*$ ]]; then
  echo "ERROR: num_commits must be a positive integer, got '$N'" >&2
  exit 1
fi

REPO_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$REPO_ROOT"

# Verify git history depth
TOTAL_COMMITS=$(git rev-list --count HEAD 2>/dev/null || echo 0)
if [ "$TOTAL_COMMITS" -eq 0 ]; then
  echo "ERROR: No git history found. Is this a git repository?" >&2
  exit 1
fi

if [ "$TOTAL_COMMITS" -lt "$N" ]; then
  echo "WARNING: Only $TOTAL_COMMITS commits available (requested $N). Checking all." >&2
  N=$((TOTAL_COMMITS - 1))
  if [ "$N" -le 0 ]; then
    CHANGED_FILES=$(git diff-tree --no-commit-id --name-only -r HEAD)
  else
    CHANGED_FILES=$(git diff --name-only "HEAD~${N}..HEAD")
  fi
else
  CHANGED_FILES=$(git diff --name-only "HEAD~${N}..HEAD")
fi

# Exclude files that don't affect spec accuracy
# composer.json is excluded because cross-package constraint bumps (the
# common edit shape — `^0.1` → `^0.1.0-alpha.N`) are release artifacts,
# not architectural changes. Genuinely spec-affecting composer.json
# edits (new packages, autoload changes) surface via the source files
# they bring in.
CHANGED_FILES=$(echo "$CHANGED_FILES" | grep -vE '(_test|Test)\.php$|\.claude/|composer\.lock$|composer\.json$|package-lock\.json$|CLAUDE\.md$|/vendor/|\.layers$|phpunit\.xml|phpstan\.neon|(^|/)\.gitkeep$' || true)

# package.json is not blanket-excluded — structural edits (new scripts,
# workspaces, exports, entry points) genuinely do affect specs. But
# dep-version-only bumps (the shape dependabot produces) are non-
# structural. Drop any package.json whose diff touches only
# "dependencies" / "devDependencies" version strings.
is_pure_dep_bump() {
  local diff="$1"
  # Hunk body lines only: drop file headers (+++/---) and hunk headers (@@).
  local changed_lines
  changed_lines=$(echo "$diff" | grep -E '^[+-][^+-]' || true)
  [ -z "$changed_lines" ] && return 1
  # A dep-version line has shape:  "<pkg>": "<semver-ish>"[,]
  # Value must start with an optional range prefix then a digit — ruling
  # out paths, URLs, scopes like "@waaseyaa/foo", and arbitrary strings.
  local non_match
  non_match=$(echo "$changed_lines" | grep -vE '^[+-][[:space:]]*"[^"]+"[[:space:]]*:[[:space:]]*"[~^<>=]*[0-9][0-9a-zA-Z.+-]*"[[:space:]]*,?[[:space:]]*$' || true)
  [ -z "$non_match" ]
}

FILTERED_FILES=""
while IFS= read -r changed_file; do
  [ -z "$changed_file" ] && continue
  if [[ "$(basename "$changed_file")" == "package.json" ]]; then
    file_diff=$(git diff "HEAD~${N}..HEAD" -- "$changed_file" 2>/dev/null || true)
    if is_pure_dep_bump "$file_diff"; then
      continue
    fi
  fi
  FILTERED_FILES+="$changed_file"$'\n'
done <<< "$CHANGED_FILES"
CHANGED_FILES=$(echo -n "$FILTERED_FILES")

if [ -z "$CHANGED_FILES" ]; then
  echo "No spec-affecting changes in last ${N} commits."
  exit 0
fi

echo "=== Drift Detector ==="
echo "Checking last ${N} commits for spec drift..."
echo ""

# Mapping: directory pattern -> spec file
declare -A PATTERN_TO_SPEC=(
  ["packages/entity/"]="docs/specs/entity-system.md"
  ["packages/entity-storage/"]="docs/specs/entity-system.md"
  ["packages/field/"]="docs/specs/entity-system.md"
  ["packages/config/"]="docs/specs/entity-system.md"
  ["packages/access/"]="docs/specs/access-control.md"
  ["packages/api/"]="docs/specs/api-layer.md"
  ["packages/routing/"]="docs/specs/api-layer.md"
  ["packages/foundation/"]="docs/specs/infrastructure.md"
  ["packages/cache/"]="docs/specs/infrastructure.md"
  ["packages/debug/"]="docs/specs/debugging-dx.md"
  ["packages/database-legacy/"]="docs/specs/infrastructure.md"
  ["packages/error-handler/"]="docs/specs/debugging-dx.md"
  ["packages/plugin/"]="docs/specs/infrastructure.md"
  ["packages/i18n/"]="docs/specs/infrastructure.md"
  ["packages/queue/"]="docs/specs/infrastructure.md"
  ["packages/state/"]="docs/specs/infrastructure.md"
  ["packages/validation/"]="docs/specs/infrastructure.md"
  ["packages/typed-data/"]="docs/specs/infrastructure.md"
  ["packages/testing/"]="docs/specs/infrastructure.md"
  ["packages/mail/"]="docs/specs/infrastructure.md"
  ["packages/http-client/"]="docs/specs/infrastructure.md"
  ["packages/admin/"]="docs/specs/admin-spa.md"
  ["packages/note/"]="docs/specs/ingestion-defaults.md"
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
  ["public/"]="docs/specs/middleware-pipeline.md"
)

declare -A AFFECTED_SPECS=()
declare -A SPEC_CHANGES=()

record_spec() {
  local spec="$1" file="$2"
  AFFECTED_SPECS["$spec"]=1
  SPEC_CHANGES["$spec"]="${SPEC_CHANGES[$spec]:-}  $file\n"
}

while IFS= read -r file; do
  [ -z "$file" ] && continue

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
      record_spec "docs/specs/middleware-pipeline.md" "$file"
      ;;
  esac
done <<< "$CHANGED_FILES"

if [ "${#AFFECTED_SPECS[@]}" -eq 0 ]; then
  echo "No specs affected by recent changes."
  exit 0
fi

echo "Affected specs:"
echo ""

STALE_COUNT=0
for spec in $(printf '%s\n' "${!AFFECTED_SPECS[@]}" | sort); do
  spec_path="$REPO_ROOT/$spec"
  if [ -f "$spec_path" ]; then
    # Compare git commit timestamps: spec last touched vs service code last touched
    spec_last_commit=$(git log -1 --format=%ct -- "$spec" 2>/dev/null)
    spec_last_commit=${spec_last_commit:-0}

    # Find the latest commit time for any matched service file
    service_last_commit=0
    for pattern in "${!PATTERN_TO_SPEC[@]}"; do
      if [ "${PATTERN_TO_SPEC[$pattern]}" = "$spec" ]; then
        pattern_commit=$(git log -1 --format=%ct -- "$pattern" ':!*/vendor/*' ':!*Test.php' ':!*_test.php' ':!*composer.json' ':!*composer.lock' 2>/dev/null)
        pattern_commit=${pattern_commit:-0}
        if [ "$pattern_commit" -gt "$service_last_commit" ]; then
          service_last_commit=$pattern_commit
        fi
      fi
    done

    if [ "$spec_last_commit" -lt "$service_last_commit" ]; then
      echo "  STALE: $spec"
      echo "    Fix: Review and update this spec to reflect recent changes"
      STALE_COUNT=$((STALE_COUNT + 1))
    else
      echo "  OK: $spec"
    fi
  else
    echo "  MISSING: $spec"
    echo "    Fix: Create this spec file to document the package"
    STALE_COUNT=$((STALE_COUNT + 1))
  fi

  echo "    Changed files:"
  echo -e "${SPEC_CHANGES[$spec]}" | sort -u | grep -v '^[[:space:]]*$' | head -10 | sed 's/^/      /'
done

echo ""
if [ $STALE_COUNT -gt 0 ]; then
  echo "$STALE_COUNT spec(s) need review. Update specs before merging."
  exit 1
else
  echo "All affected specs are up to date."
  exit 0
fi
