#!/usr/bin/env bash
#
# drift-detector.sh — Detect stale specs by comparing git timestamps.
#
# Usage: tools/drift-detector.sh [N]
#   N = number of recent commits to check (default: 5)
#
# Exit codes:
#   0 = all specs up to date (or no specs affected)
#   1 = one or more specs are stale or missing

set -euo pipefail

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
CHANGED_FILES=$(echo "$CHANGED_FILES" | grep -vE '(_test|Test)\.php$|\.claude/|composer\.lock$|CLAUDE\.md$|/vendor/|\.layers$|phpunit\.xml|phpstan\.neon' || true)

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
  ["packages/database-legacy/"]="docs/specs/infrastructure.md"
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
        pattern_commit=$(git log -1 --format=%ct -- "$pattern" ':!*/vendor/*' ':!*Test.php' ':!*_test.php' 2>/dev/null)
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
