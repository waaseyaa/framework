#!/usr/bin/env bash
# Create an annotated tag, generate changelog, push tag, and optionally create a GitHub release.
# Usage: scripts/release.sh <version>  (e.g., scripts/release.sh v1.0.1)
set -euo pipefail

VERSION="${1:?Usage: scripts/release.sh <version>}"

[[ "$VERSION" =~ ^v[0-9]+\.[0-9]+\.[0-9]+$ ]] || { echo "ERROR: version must match vX.Y.Z"; exit 1; }

# Must be on main
BRANCH=$(git branch --show-current)
[ "$BRANCH" = "main" ] || { echo "ERROR: must be on main (currently $BRANCH)"; exit 1; }

# Must be clean and up to date
git fetch origin main --quiet
LOCAL=$(git rev-parse HEAD)
REMOTE=$(git rev-parse origin/main)
[ "$LOCAL" = "$REMOTE" ] || { echo "ERROR: local main differs from origin/main"; exit 1; }

# Tag must not exist
git rev-parse "$VERSION" > /dev/null 2>&1 && { echo "ERROR: tag $VERSION already exists"; exit 1; }

# Changelog
PREV_TAG=$(git tag --sort=-v:refname | head -1)
CHANGELOG=""
if [ -n "$PREV_TAG" ]; then
    CHANGELOG=$(git log "$PREV_TAG"..HEAD --oneline --no-merges | sed 's/^/- /')
fi
[ -z "$CHANGELOG" ] && CHANGELOG="- No changes since last tag"

echo "=== Release $VERSION ==="
echo ""
echo "Changelog since $PREV_TAG:"
echo "$CHANGELOG"
echo ""

# Create + push annotated tag
git tag -a "$VERSION" -m "Release $VERSION

$CHANGELOG"
git push origin "$VERSION"
echo "Tag $VERSION pushed."

# GitHub release (optional)
if command -v gh > /dev/null 2>&1; then
    gh release create "$VERSION" --title "Release $VERSION" --notes "$CHANGELOG" --verify-tag
    echo "GitHub release created."
else
    echo "NOTICE: gh CLI not found — create GitHub release manually."
fi
