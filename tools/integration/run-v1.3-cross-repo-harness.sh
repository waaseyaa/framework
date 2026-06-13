#!/usr/bin/env bash
set -euo pipefail

WAASEYAA_ROOT="${WAASEYAA_ROOT:-$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)}"
MINOO_ROOT="${MINOO_ROOT:-/home/jones/dev/minoo}"
ARTIFACT_PATH="${ARTIFACT_PATH:-$WAASEYAA_ROOT/docs/history/plans/artifacts/v1.3-cross-repo-harness.md}"

mkdir -p "$(dirname "$ARTIFACT_PATH")"

log() {
  printf '%s\n' "$*"
}

run_step() {
  local name="$1"
  local cmd="$2"

  log "[harness] $name"
  local output
  if ! output=$(bash -lc "$cmd" 2>&1); then
    {
      printf '## %s\n\n' "$name"
      printf -- '- Status: FAIL\n\n'
      printf '```text\n%s\n```\n\n' "$output"
    } >> "$ARTIFACT_PATH"
    log "$output"
    exit 1
  fi

  {
    printf '## %s\n\n' "$name"
    printf -- '- Status: PASS\n\n'
    printf '```text\n%s\n```\n\n' "$output"
  } >> "$ARTIFACT_PATH"
}

if WAASEYAA_SHA=$(git -C "$WAASEYAA_ROOT" rev-parse --short HEAD 2>/dev/null); then
  :
else
  WAASEYAA_SHA="n/a"
fi
if MINOO_SHA=$(git -C "$MINOO_ROOT" rev-parse --short HEAD 2>/dev/null); then
  :
else
  MINOO_SHA="n/a"
fi
RUN_TS=$(date -u +"%Y-%m-%dT%H:%M:%SZ")

cat > "$ARTIFACT_PATH" <<HEADER
# v1.3 Cross-Repo Extension Harness Artifact

- Run timestamp (UTC): $RUN_TS
- Waaseyaa root: $WAASEYAA_ROOT
- Waaseyaa commit: $WAASEYAA_SHA
- Minoo root: $MINOO_ROOT
- Minoo commit: $MINOO_SHA
HEADER

run_step "Waaseyaa extension scaffold contract probe" \
  "php \"$WAASEYAA_ROOT/bin/waaseyaa\" scaffold:extension --id harness_ext --label \"Harness Extension\" --package harness/ext | head -n 60"

run_step "Waaseyaa extension integration unit matrix" \
  "\"$WAASEYAA_ROOT/vendor/bin/phpunit\" --configuration \"$WAASEYAA_ROOT/phpunit.xml.dist\" \"$WAASEYAA_ROOT/packages/cli/tests/Unit/Command/ExtensionScaffoldCommandTest.php\" \"$WAASEYAA_ROOT/packages/foundation/tests/Unit/Kernel/AbstractKernelExtensionRunnerTest.php\" \"$WAASEYAA_ROOT/packages/mcp/tests/Unit/McpControllerTest.php\""

run_step "Minoo command catalog probe" \
  "php \"$MINOO_ROOT/bin/waaseyaa\" list --no-ansi"

run_step "Minoo extension scaffold contract probe" \
  "php \"$MINOO_ROOT/bin/waaseyaa\" scaffold:extension --id minoo_ext --label \"Minoo Extension\" --package minoo/ext | head -n 60"

run_step "Minoo workflow smoke tests" \
  "php \"$MINOO_ROOT/vendor/bin/phpunit\" --configuration \"$MINOO_ROOT/phpunit.xml.dist\" \"$MINOO_ROOT/tests/Smoke/BootSmokeTest.php\" \"$MINOO_ROOT/tests/Integration/EditorialWorkflowIntegrationTest.php\" --filter '/testDraftReviewPublishFlowWithRolePermissions|testValidationListenerBlocksManualInvalidTransitionOnSave/'"

log "[harness] PASS - artifact written to $ARTIFACT_PATH"
