---
work_package_id: WP04
title: "Admin SPA — useMediaVersions composable, MediaVersionBrowser component, media-edit integration, docs, CHANGELOG"
dependencies:
  - WP03
requirement_refs:
  - FR-012
  - FR-015
  - NFR-003
planning_base_branch: main
merge_target_branch: main
branch_strategy: "Branches from WP03 merge commit. Completed work merges back into main."
subtasks:
  - T-N
  - T-O
authoritative_surface: "packages/admin/app/components/media/MediaVersionBrowser.vue"
execution_mode: code_change
owned_files:
  - packages/admin/app/composables/useMediaVersions.ts
  - packages/admin/app/components/media/MediaVersionBrowser.vue
  - packages/admin/app/pages/media/[id].vue
  - packages/admin/app/i18n/en.json
  - packages/admin/tests/unit/composables/useMediaVersions.test.ts
  - packages/admin/e2e/media-versions.spec.ts
  - docs/specs/versioned-blob-media.md
  - docs/specs/entity-storage-two-axis.md
  - CLAUDE.md
  - CHANGELOG.md
tags: ["admin-spa", "media", "versioning", "frontend"]
history: []
---

# WP04 — Admin SPA version browser + docs

**Mission:** `versioned-blob-media-abstraction-01KSEFTJ`
**Spec:** [../spec.md](../spec.md) | **Plan:** [../plan.md](../plan.md)
**Depends on:** WP03.

## Pattern references — READ FIRST

- `packages/admin/app/composables/useQueueJobs.ts` — composable shape.
- M5A WP02 — frontend task pattern with composable + table component (see `packages/admin/app/components/ai/AiUsageTable.vue`).
- `packages/admin/app/i18n/en.json` — key style.

## Subtasks

### T-N — Composable + component + page integration

**Composable** `packages/admin/app/composables/useMediaVersions.ts`:
```ts
export function useMediaVersions() {
  const versions = ref<MediaVersion[]>([])
  const loading = ref(false)
  const error = ref<Error | null>(null)
  const api = useApi()
  async function fetchVersions(mediaUuid: string) {
    loading.value = true; error.value = null
    try {
      const r = await api.get(`/api/media/${mediaUuid}/versions`)
      versions.value = r.data
    } catch (e) { error.value = e as Error }
    finally { loading.value = false }
  }
  return { versions, loading, error, fetchVersions }
}
```
TS types mirror `MediaVersionResource` from WP03 (camelCase): `vid`, `mediaUuid`, `blobUri`, `mime`, `sizeBytes`, `sha256`, `createdAt`, `createdBy`.

**Component** `packages/admin/app/components/media/MediaVersionBrowser.vue`:
- Props: `mediaUuid: string`.
- Calls `fetchVersions(mediaUuid)` on mount.
- Table: vid | mime | size (formatted via util `formatBytes`) | sha256 (first 12 chars + full in tooltip) | createdAt (relative time) | createdBy (uid; future: lookup display name) | actions (View — disabled placeholder if binary endpoint not shipped).
- Empty state when `versions.length === 0` and not loading.
- Loading + error states.

**Page integration** `packages/admin/app/pages/media/[id].vue`:
- If the file exists, append `<MediaVersionBrowser :media-uuid="route.params.id" />` to the page below the existing editor.
- If it doesn't exist, create the minimal viable shell (page that loads a media item by id; do NOT build a full editor — just enough to mount the browser).

**i18n keys** in `packages/admin/app/i18n/en.json`:
`media_versions_title`, `media_version_col_vid`, `media_version_col_mime`, `media_version_col_size`, `media_version_col_hash`, `media_version_col_created`, `media_version_col_creator`, `media_version_col_actions`, `media_version_action_view`, `media_versions_empty`.

### T-O — Tests + docs + CHANGELOG

- `packages/admin/tests/unit/composables/useMediaVersions.test.ts` — vitest: success populates `versions`; failure sets `error`, leaves `versions` empty.
- `packages/admin/e2e/media-versions.spec.ts` — Playwright smoke (deferred run).
- `docs/specs/versioned-blob-media.md` — new spec (~250-400 lines) per plan.md §T-O.
- `docs/specs/entity-storage-two-axis.md` — append a single cross-reference paragraph: "Blob-versioning extends this substrate's revisionable-axis pattern at the blob layer; see `docs/specs/versioned-blob-media.md`." Do NOT otherwise modify the two-axis spec.
- `CLAUDE.md` orchestration table — add row `packages/media/src/Version/*` → `docs/specs/versioned-blob-media.md`.
- `CHANGELOG.md` `[Unreleased]` → **Added**: `Versioned blob media abstraction (packages/media/Version): MediaVersion entity, content-addressed storage (cas:// URI scheme), per-save versioning, per-version access policy, JSON:API listing endpoints, admin SPA version browser. Closes gap-matrix A1 / alpha-to-beta-plan §1 item #5. Audit-event-kind enum extended additively with media.version.{created,read,dedup_hit}. (versioned-blob-media-abstraction-01KSEFTJ)`.

## Verification gate (in lane worktree)

1. `cd packages/admin && npm install`.
2. `npm test && npm run typecheck && npm run lint`.
3. `bin/check-no-secrets` (docs clean).
4. `tools/drift-detector.sh` if present.

## Commit + handoff

- `feat(admin): useMediaVersions composable`
- `feat(admin): MediaVersionBrowser component + media-edit page integration`
- `feat(admin): i18n keys for media versioning`
- `test(admin): useMediaVersions vitest + Playwright smoke`
- `docs(specs): versioned-blob-media.md + entity-storage-two-axis cross-ref + CLAUDE.md + CHANGELOG`

```
spec-kitty agent tasks mark-status T-N T-O --status done --mission versioned-blob-media-abstraction-01KSEFTJ
spec-kitty agent tasks move-task WP04 --to for_review --mission versioned-blob-media-abstraction-01KSEFTJ --note "Admin browser + docs ready; Playwright smoke deferred (lane worktree)"
```

## Report back with

1. Commit SHAs.
2. `npm test && npm run typecheck` green output.
3. The two-axis spec diff (must be a single-paragraph cross-reference append — no other changes).
4. CLAUDE.md orchestration-table diff.
5. Whether the existing `pages/media/[id].vue` was reused or freshly created.

## Activity Log
- 2026-05-25T05:54:52Z – unknown – Moved to in_progress
- 2026-05-25T06:00:45Z – unknown – Moved to for_review
- 2026-05-25T06:01:18Z – unknown – Opus review: lane-a disciplined; clean commit per WP; specs stamped including CLAUDE.md orchestration table row; tests pass
- 2026-05-26T18:56:01Z – unknown – Done override: Sprint merge to main
