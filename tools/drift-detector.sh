#!/usr/bin/env bash
#
# drift-detector.sh — Map recent git changes to subsystem specs that may need review.
#
# Usage: tools/drift-detector.sh [N]
#   N = number of recent commits to check (default: 5)

set -euo pipefail

N="${1:-5}"

# Verify we're in a git repo.
if ! git rev-parse --is-inside-work-tree &>/dev/null; then
  echo "Error: not inside a git repository." >&2
  exit 1
fi

# Collect changed files.
changed_files=$(git diff --name-only "HEAD~${N}..HEAD" 2>/dev/null) || {
  echo "Error: could not compute diff for last ${N} commits." >&2
  exit 1
}

if [ -z "$changed_files" ]; then
  echo "No files changed in last ${N} commits."
  echo "All specs up to date."
  exit 0
fi

echo "Checking files changed in last ${N} commits..."
echo ""

declare -A affected_specs

map_file_to_specs() {
  local file="$1"
  local specs=""

  # Entity system
  case "$file" in
    packages/entity/*|packages/entity-storage/*|packages/field/*|packages/config/*)
      specs="$specs docs/specs/entity-system.md" ;;
  esac

  # Access control
  case "$file" in
    packages/access/*|packages/user/src/Middleware/*)
      specs="$specs docs/specs/access-control.md" ;;
  esac

  # Field access (FieldAccess in access, Schema in api)
  case "$file" in
    packages/access/src/*FieldAccess*)
      specs="$specs docs/specs/field-access.md" ;;
  esac
  case "$file" in
    packages/api/src/*Schema*)
      specs="$specs docs/specs/field-access.md" ;;
  esac

  # API layer
  case "$file" in
    packages/api/*|packages/routing/*)
      specs="$specs docs/specs/api-layer.md" ;;
  esac

  # Infrastructure
  case "$file" in
    packages/foundation/*|packages/cache/*|packages/database-legacy/*)
      specs="$specs docs/specs/infrastructure.md" ;;
  esac

  # Package discovery
  case "$file" in
    packages/foundation/src/*Provider*)
      specs="$specs docs/specs/package-discovery.md" ;;
  esac
  case "$file" in
    packages/plugin/*)
      specs="$specs docs/specs/package-discovery.md" ;;
  esac

  # Middleware pipeline
  case "$file" in
    public/index.php)
      specs="$specs docs/specs/middleware-pipeline.md" ;;
  esac
  case "$file" in
    packages/*/src/Middleware/*)
      specs="$specs docs/specs/middleware-pipeline.md" ;;
  esac

  # Admin SPA
  case "$file" in
    packages/admin/*)
      specs="$specs docs/specs/admin-spa.md" ;;
  esac

  # AI integration
  case "$file" in
    packages/ai-*/*)
      specs="$specs docs/specs/ai-integration.md" ;;
  esac

  # MCP endpoint
  case "$file" in
    packages/mcp/*)
      specs="$specs docs/specs/mcp-endpoint.md" ;;
  esac

  echo "$specs"
}

echo "Files changed -> Affected specs:"

while IFS= read -r file; do
  raw_specs=$(map_file_to_specs "$file")
  if [ -z "$raw_specs" ]; then
    continue
  fi

  # Deduplicate specs for this file.
  declare -A seen_for_file
  display_specs=""
  for spec in $raw_specs; do
    if [ -z "${seen_for_file[$spec]+x}" ]; then
      seen_for_file[$spec]=1
      affected_specs[$spec]=1
      if [ -n "$display_specs" ]; then
        display_specs="$display_specs, $spec"
      else
        display_specs="$spec"
      fi
    fi
  done
  unset seen_for_file

  echo "  $file -> $display_specs"
done <<< "$changed_files"

echo ""

count=${#affected_specs[@]}
if [ "$count" -eq 0 ]; then
  echo "All specs up to date."
else
  echo "$count spec(s) may need review:"
  for spec in $(printf '%s\n' "${!affected_specs[@]}" | sort); do
    echo "  - $spec"
  done
fi
