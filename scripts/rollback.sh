#!/usr/bin/env bash
# Rollback to a previous tag and re-deploy.
# Usage: scripts/rollback.sh <tag>
set -euo pipefail

TAG="${1:?Usage: scripts/rollback.sh <tag>}"

git rev-parse "$TAG" > /dev/null 2>&1 || {
    echo "ERROR: tag $TAG not found. Available tags:"
    git tag --sort=-v:refname | head -5
    exit 1
}

TAG_SHA=$(git rev-parse "$TAG")
CURRENT_SHA=$(git rev-parse HEAD)
TS=$(date -u +%Y-%m-%dT%H:%M:%SZ)

echo "=== Rollback ==="
echo "  From: $CURRENT_SHA"
echo "  To:   $TAG ($TAG_SHA)"

# Record rollback metadata
mkdir -p build/deploy
jq -n \
  --arg action "rollback" \
  --arg target_tag "$TAG" \
  --arg target_sha "$TAG_SHA" \
  --arg from_sha "$CURRENT_SHA" \
  --arg ts "$TS" \
  '{action: $action, target_tag: $target_tag, target_sha: $target_sha, rolled_back_from: $from_sha, timestamp: $ts}' \
  > build/deploy/rollback-metadata.json

# Checkout and redeploy
git checkout "$TAG"

if [ -f scripts/deploy.sh ]; then
    bash scripts/deploy.sh production
else
    echo "WARNING: scripts/deploy.sh not found — manual deployment required"
fi

echo "=== Rollback to $TAG complete ==="
echo "IMPORTANT: Investigate the issue and deploy a fix."
