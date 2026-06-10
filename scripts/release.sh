#!/usr/bin/env bash
# Release script: validates, updates CHANGELOG.md, tags, pushes, and optionally creates GitHub release.
# Usage: scripts/release.sh <version>  (e.g., scripts/release.sh v1.0.0 or v0.1.0-alpha.5)
#
# DEPRECATED AS THE CANONICAL PATH (#1385).
# Prefer the `Cut Release` workflow:
#
#     gh workflow run release-cut.yml -f version=v0.1.0-alpha.NNN
#
# This script is kept as a fallback for offline emergencies (broken
# Actions runners, network partitions). The interactive
# `Create GitHub release? [y/N]` prompt below is redundant — split.yml's
# `publish-github-release` job creates the GitHub Release on every tag.
# See docs/specs/workflow.md → "Cutting Releases".
#
# RELEASE GATE: tags require green Linux CI (alpha.200–202 red-at-tag
# post-mortem). This script enforces the gate on the release BASE via the
# Actions API; it cannot CI-prove the release commit itself the way
# release-cut.yml's gate branch does — one more reason it is not the
# canonical path. WAASEYAA_EMERGENCY_RELEASE=1 skips the gate for genuine
# offline emergencies only.
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

# Must be a real split-capable monorepo revision
bash bin/check-monorepo-release-shape HEAD >/dev/null

# Must be synced with origin
git fetch origin main --quiet
LOCAL=$(git rev-parse HEAD)
REMOTE=$(git rev-parse origin/main)
[ "$LOCAL" = "$REMOTE" ] || { echo "ERROR: local main differs from origin/main"; exit 1; }

# Tag must not exist
git rev-parse "$VERSION" > /dev/null 2>&1 && { echo "ERROR: tag $VERSION already exists"; exit 1; }

# Release gate: green Linux CI on the release base. Local gate runs (any OS,
# especially Windows) are advisory only — the Actions API is authoritative.
if [ "${WAASEYAA_EMERGENCY_RELEASE:-}" != "1" ]; then
    command -v gh > /dev/null 2>&1 || { echo "ERROR: gh CLI required for the CI release gate. Set WAASEYAA_EMERGENCY_RELEASE=1 only for a genuine offline emergency."; exit 1; }
    bash bin/wait-for-green-ci "$LOCAL" 0 || {
        echo "ERROR: CI is not green on HEAD (${LOCAL}). Releases cannot be cut from a red base."
        echo "       Prefer: gh workflow run release-cut.yml -f version=${VERSION}"
        exit 1
    }
else
    echo "WARNING: WAASEYAA_EMERGENCY_RELEASE=1 — skipping the CI release gate. This tag will NOT be CI-proven."
fi

# CHANGELOG.md must exist and have Unreleased content
[ -f CHANGELOG.md ] || { echo "ERROR: CHANGELOG.md not found"; exit 1; }
grep -q '## \[Unreleased\]' CHANGELOG.md || { echo "ERROR: no [Unreleased] section in CHANGELOG.md"; exit 1; }

# Check [Unreleased] has content (not just empty headers)
UNRELEASED_CONTENT=$(sed -n '/^## \[Unreleased\]/,/^## \[/{/^## \[/d;/^$/d;/^### /d;p;}' CHANGELOG.md)
[ -n "$UNRELEASED_CONTENT" ] || { echo "ERROR: [Unreleased] section has no entries"; exit 1; }

# Sync internal waaseyaa/* version constraints across packages/*/composer.json
# (matches release-cut.yml's behaviour; kept in lockstep per #1385).
bin/sync-internal-versions "$SEMVER"

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

# Stamp the VERSION file to match the tag (lockstep with release-cut.yml).
# Without this, the file stays frozen and cold clones misreport the version.
printf '%s\n' "$SEMVER" > VERSION

# Commit changelog, version, synced manifests, tag, push.
# packages/*/composer.json staging matches release-cut.yml — omitting it was
# the alpha.178 incident (synced constraints discarded, 200+ CP-NEW failures).
git add CHANGELOG.md VERSION packages/*/composer.json
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
