#!/usr/bin/env bash
# splitsh-lite-simulate.sh — Dry-run simulation of the monorepo split.
#
# USAGE:
#   bash scripts/splitsh-lite-simulate.sh <package-path> <target-repo-name>
#
# EXAMPLE:
#   bash scripts/splitsh-lite-simulate.sh packages/foundation waaseyaa-foundation
#
# This script does NOT push, does NOT modify history, does NOT require splitsh-lite
# to be installed. It prints what would happen if the real split ran.

set -euo pipefail

PACKAGE_PATH="${1:?Usage: $0 <package-path> <target-repo-name>}"
TARGET_REPO="${2:?Usage: $0 <package-path> <target-repo-name>}"
REPO_ROOT="$(git rev-parse --show-toplevel)"

echo "====================================================="
echo " splitsh-lite DRY RUN SIMULATION"
echo "====================================================="
echo " Package path : ${PACKAGE_PATH}"
echo " Target repo  : github.com/waaseyaa/${TARGET_REPO}"
echo " Repo root    : ${REPO_ROOT}"
echo "====================================================="
echo ""

# Verify the package path exists and has a composer.json
if [[ ! -f "${REPO_ROOT}/${PACKAGE_PATH}/composer.json" ]]; then
  echo "ERROR: ${PACKAGE_PATH}/composer.json not found. Aborting."
  exit 1
fi

PACKAGE_NAME=$(jq -r '.name' "${REPO_ROOT}/${PACKAGE_PATH}/composer.json")
echo "Package name  : ${PACKAGE_NAME}"
echo ""

# Show commits that touch this path
echo "--- Commits that would be included in the split ---"
git -C "${REPO_ROOT}" log --pretty=format:"%h %ad %s" --date=short -- "${PACKAGE_PATH}" | head -20
echo ""
echo "  (showing last 20; run without | head to see all)"
echo ""

# Count total commits
TOTAL=$(git -C "${REPO_ROOT}" rev-list HEAD -- "${PACKAGE_PATH}" | wc -l | tr -d ' ')
echo "Total commits touching ${PACKAGE_PATH}: ${TOTAL}"
echo ""

# Show current tag
LATEST_TAG=$(git -C "${REPO_ROOT}" describe --tags --abbrev=0 2>/dev/null || echo "no tags")
echo "Latest monorepo tag: ${LATEST_TAG}"
echo ""

# Print the actual splitsh-lite command that would run
echo "--- Command that WOULD be executed (commented out for safety) ---"
echo ""
echo "# Install splitsh-lite:"
echo "# curl -sL https://github.com/splitsh/lite/releases/latest/download/lite_linux_amd64.tar.gz | tar xz"
echo ""
echo "# Run split (outputs the SHA of the new root commit):"
echo "# SHA=\$(./splitsh-lite --prefix=${PACKAGE_PATH})"
echo "# echo \"Split SHA: \$SHA\""
echo ""
echo "# Push to mirror repo (tag only — no history modification to monorepo):"
echo "# git push https://github.com/waaseyaa/${TARGET_REPO}.git \"\$SHA:refs/heads/main\" --force-with-lease"
echo "# git push https://github.com/waaseyaa/${TARGET_REPO}.git ${LATEST_TAG}"
echo ""
echo "--- GitHub Actions equivalent ---"
echo ""
echo "  uses: symplify/monorepo-split-github-action@v2"
echo "  with:"
echo "    package-directory: ${PACKAGE_PATH}"
echo "    split-repository-organization: waaseyaa"
echo "    split-repository-name: ${TARGET_REPO}"
echo "    tag: \${{ github.ref_name }}"
echo ""
echo "====================================================="
echo " DRY RUN COMPLETE — no changes made"
echo "====================================================="
