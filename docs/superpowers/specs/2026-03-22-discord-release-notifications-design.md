# Discord Release Notifications — Design Spec

**Date:** 2026-03-22
**Status:** Approved
**Scope:** Waaseyaa releases only

## Problem

No automated notification when new Waaseyaa versions are tagged. Contributors and users must manually check GitHub for releases.

## Solution

A standalone GitHub Actions workflow that sends a rich Discord embed when a `v*` tag is pushed. Independent of the release pipeline — never blocks deploys.

## Trigger

```yaml
on:
  push:
    tags: ['v*']
```

## Workflow: `.github/workflows/discord-release.yml`

Single job with `permissions: contents: read` (least-privilege). Three steps:

1. **Checkout** — `actions/checkout@v4` with `fetch-depth: 0` (full history for changelog generation)
2. **Generate changelog** — Shell script:
   - Derive tag: `TAG=${{ github.ref_name }}`
   - Find previous tag: `git tag --sort=-version:refname | grep -v "^$TAG$" | head -1`
   - Generate commit log: `git log --oneline $PREV_TAG..$TAG`
   - Truncate to 1900 characters if needed (Discord embed description limit is 4096, but shorter reads better)
   - Export `CHANGELOG` and `PREV_TAG` via `$GITHUB_ENV` for the next step
3. **Send Discord embed** — `actions/github-script@v7`:
   - Secret exposed via step-level `env:` block:
     ```yaml
     env:
       DISCORD_WEBHOOK_URL: ${{ secrets.DISCORD_WEBHOOK_URL }}
     ```
   - Script reads `process.env.DISCORD_WEBHOOK_URL` (never interpolate secrets in script body)
   - Build JSON payload with rich embed fields
   - POST via `fetch()` (Node 20+ native on `ubuntu-latest`)
   - Best-effort: log warning on failure, never fail the workflow run

## Rich Embed Structure

| Field | Value | Source |
|---|---|---|
| Title | Tag name (e.g., `v0.1.0-alpha.40`) | `${{ github.ref_name }}` |
| Description | Changelog (commits since previous tag) | `git log --oneline` |
| Color | Green (`0x2ecc71`) | Hardcoded |
| URL | GitHub tag/release page | `https://github.com/${{ github.repository }}/releases/tag/${{ github.ref_name }}` |
| Footer | Active milestone info | GitHub API via `actions/github-script` |
| Timestamp | ISO 8601 tag push time | `new Date().toISOString()` |

## Error Handling

- Discord webhook failure: caught in try/catch, logged as warning, workflow succeeds
- No previous tag (first release): changelog shows "Initial release"
- Empty changelog: shows "No changes recorded"

## Secrets Required

| Secret | Purpose | Status |
|---|---|---|
| `DISCORD_WEBHOOK_URL` | Discord webhook endpoint | Set |

## Out of Scope

- Multi-repo announcements (future consideration)
- Deploy success/failure notifications
- Interactive Discord bot commands
- Milestone completion announcements

## Future Extensions

These are noted for context, not committed to:
- Add deploy-success confirmation as a second notification type
- Expand to other repos in the workspace
- Milestone completion announcements
