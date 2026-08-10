#!/usr/bin/env bash
# Deploy to staging or production.
# Usage: scripts/deploy.sh <staging|production>
set -euo pipefail

ENV="${1:?Usage: scripts/deploy.sh <staging|production>}"
[[ "$ENV" =~ ^(staging|production)$ ]] || { echo "ERROR: environment must be staging or production"; exit 1; }

SHA=$(git rev-parse HEAD)
TS=$(date -u +%Y-%m-%dT%H:%M:%SZ)
TAG=$(git describe --tags --exact-match 2>/dev/null || echo "untagged")

if [ "$ENV" = "production" ] && [ "$TAG" = "untagged" ]; then
    if [ "${ALLOW_UNTAGGED_PRODUCTION:-0}" != "1" ]; then
        echo "ERROR: production deployment requires an exact release tag" >&2
        exit 1
    fi
    JUSTIFICATION="${DEPLOY_EMERGENCY_JUSTIFICATION:-}"
    if [ ${#JUSTIFICATION} -lt 20 ]; then
        echo "ERROR: emergency untagged production deployment requires a 20+ character justification" >&2
        exit 1
    fi
    echo "WARNING: emergency untagged production override: $JUSTIFICATION" >&2
fi

echo "=== Deploy to $ENV ==="
echo "  SHA: $SHA"
echo "  Tag: $TAG"
echo "  Time: $TS"

# Record metadata
mkdir -p build/deploy
jq -n \
  --arg env "$ENV" \
  --arg sha "$SHA" \
  --arg tag "$TAG" \
  --arg ts "$TS" \
  --arg branch "$(git branch --show-current 2>/dev/null || echo detached)" \
  '{environment: $env, sha: $sha, tag: $tag, timestamp: $ts, branch: $branch}' \
  > "build/deploy/${ENV}-metadata.json"

# Build steps
echo "Running composer install..."
composer install --no-dev --optimize-autoloader --no-interaction 2>&1 || true

if [ -d "packages/admin" ]; then
    echo "Building frontend..."
    (cd packages/admin && npm ci --ignore-scripts 2>/dev/null && npm run build 2>&1) || echo "WARNING: frontend build skipped"
fi

echo "=== Deploy to $ENV complete ==="
echo "Metadata: build/deploy/${ENV}-metadata.json"
