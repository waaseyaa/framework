#!/usr/bin/env bash
# Release script: validates, updates CHANGELOG.md, tags, pushes, and optionally creates GitHub release.
# Usage: scripts/release.sh <version>  (e.g., scripts/release.sh v1.0.0 or v0.1.0-alpha.5)
set -euo pipefail

VERSION="${1:?Usage: scripts/release.sh <version>}"
SEMVER="${VERSION#v}"

# Validate semver (vX.Y.Z or vX.Y.Z-prerelease.N)
[[ "$VERSION" =~ ^v[0-9]+\.[0-9]+\.[0-9]+(-.+)?$ ]] || { echo "ERROR: version must match vX.Y.Z or vX.Y.Z-pre.N"; exit 1; }

# Must be on main
BRANCH=$(git branch --show-current)
[ "$BRANCH" = "main" ] || { echo "ERROR: must be on main (currently $BRANCH)"; exit 1; }

# Must be clean
[ -z "$(git status --porcelain)" ] || { echo "ERROR: working tree is not clean"; exit 1; }

# Must be synced with origin
git fetch origin main --quiet
LOCAL=$(git rev-parse HEAD)
REMOTE=$(git rev-parse origin/main)
[ "$LOCAL" = "$REMOTE" ] || { echo "ERROR: local main differs from origin/main"; exit 1; }

# Tag must not exist
git rev-parse "$VERSION" > /dev/null 2>&1 && { echo "ERROR: tag $VERSION already exists"; exit 1; }

# CHANGELOG.md must exist and have Unreleased content
[ -f CHANGELOG.md ] || { echo "ERROR: CHANGELOG.md not found"; exit 1; }
grep -q '## \[Unreleased\]' CHANGELOG.md || { echo "ERROR: no [Unreleased] section in CHANGELOG.md"; exit 1; }

# Check [Unreleased] has content (not just empty headers)
UNRELEASED_CONTENT=$(sed -n '/^## \[Unreleased\]/,/^## \[/{/^## \[/d;/^$/d;/^### /d;p;}' CHANGELOG.md)
[ -n "$UNRELEASED_CONTENT" ] || { echo "ERROR: [Unreleased] section has no entries"; exit 1; }

# Extract unreleased section for tag message
TAG_MSG=$(sed -n '/^## \[Unreleased\]/,/^## \[/{/^## \[Unreleased\]/d;/^## \[/d;p;}' CHANGELOG.md)

# Portable sed in-place (GNU vs BSD)
sedi() {
    if sed --version >/dev/null 2>&1; then
        sed -i "$@"
    else
        sed -i '' "$@"
    fi
}

# Rename [Unreleased] to [X.Y.Z] and insert fresh [Unreleased]
DATE=$(date +%Y-%m-%d)
sedi "s/^## \[Unreleased\]/## [${SEMVER}] - ${DATE}/" CHANGELOG.md
sedi "/^## \[${SEMVER}\] - ${DATE}/i\\
## [Unreleased]\\
" CHANGELOG.md

echo "=== Release $VERSION ==="
echo ""
echo "Changes:"
echo "$TAG_MSG"
echo ""

# Commit changelog, tag, push
git add CHANGELOG.md
git commit -m "chore: release ${VERSION}"
git tag -a "$VERSION" -m "Release ${VERSION}

${TAG_MSG}"
git push origin main "$VERSION"
echo "Tag $VERSION pushed."

# GitHub release (optional)
if command -v gh > /dev/null 2>&1; then
    read -rp "Create GitHub release? [y/N] " confirm
    if [[ "$confirm" =~ ^[Yy]$ ]]; then
        gh release create "$VERSION" --title "Release ${VERSION}" --notes "$TAG_MSG" --verify-tag
        echo "GitHub release created."
    fi
else
    echo "NOTICE: gh CLI not found — create GitHub release manually."
fi
