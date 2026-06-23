# CL-13 — Protected file bytes: design & tradeoffs (pre-implementation)

**Status:** design for review — DO NOT implement until the open decisions (§6) are agreed.
**Grounded against:** current `main`. Backs the CL-13 backlog entry in `cleanup-backlog.md`.

## 1. The problem (grounded)

Entity-row access policies protect the **record**, not the **bytes**. `MediaAccessPolicy`
(`packages/media/src/MediaAccessPolicy.php`) and attachment's `ParentDelegatedAccessPolicy`
(`packages/attachment/src/Access/ParentDelegatedAccessPolicy.php`) gate `view`/`update`/`delete`
of the entity row, but nothing access-checks the file content.

Current upload/serve path (`packages/media/src/Http/Router/MediaRouter.php`):
- bytes are written to `resolveFilesRootDir()` = `config['files_root']` or
  `<projectRoot>/storage/files` (`:140-148`), **outside** the web root;
- the upload returns a `public://<name>` URI and a `/files/<name>` URL (`:94`, `:221-234`);
- **nothing in the framework or skeleton serves `/files/`** — `skeleton/public/` is only
  `index.php` (no symlink, no Caddyfile, no route). `/files/` is a **convention the consuming
  app wires** (typically `public/files → storage/files`), which is exactly what would expose
  bytes by URL.

So today: bytes are unreachable in a default install, but the `public://` + `/files/` convention
encourages a host to expose `storage/files` publicly — at which point **any** media/attachment
file is world-readable regardless of the entity policy. This is a latent design gap (no spec
promises gated downloads), not a claim-vs-code defect.

## 2. Goal

Protected files served through an **authorized download path** that enforces the **same access
policy gating the owning entity** (deny-by-default, fail-closed, 404-on-deny), with bytes stored
**outside any web-served tree** (a private scheme) so they are not directly reachable by URL.
Legitimately-public assets (e.g. avatars) stay public and unaffected.

## 3. Design

### 3.1 `private://` scheme → a non-served root
`LocalFileRepository` already partitions storage by URI scheme
(`resolveMetadataPath()` → `<rootDir>/<scheme>/...`). Add a `private://` scheme whose bytes live
under a root that the `/files/` convention **never** maps — e.g. `storage/private-files/`
(a sibling of `storage/files`, never symlinked into `public/`). Critical: it must NOT be
`storage/files/private/`, because a host that symlinks `public/files → storage/files` would then
serve `/files/private/...`. The private root is a distinct, documented "do not web-serve" path.

### 3.2 Entity-keyed authorized download controller
Add routes `GET /media/{id}/download` and `GET /attachment/{id}/download` (names TBD), each:
1. loads the owning entity by id;
2. runs `EntityAccessHandler::check($entity, 'view', $account)->isAllowed()` —
   **deny-by-default, fail-closed**; a denied (or missing) entity returns **404** (not 403), to
   avoid an existence oracle, mirroring `JsonApiController::notFoundDocument`;
3. resolves the entity's file URI (see §3.3) and streams the bytes from the private root via a
   `StreamedResponse` with the stored `mime_type` + a safe `Content-Disposition`.

Entity-keyed (not file-keyed) deliberately: it reuses the existing entity policy as the single
source of truth and needs no reverse file→entity index.

### 3.3 The file→entity linkage (the genuinely under-modeled part)
- **attachment:** clean — `storage_uri` is a known field in the `_data` blob
  (`AttachmentSchema.php:27`). The download controller reads `attachment.storage_uri`.
- **media:** NOT clean — `Media` is a generic `ContentEntityBase`; the file reference is
  determined per **media type (bundle)** by its source plugin config, with no single
  framework-level file-uri field, and there is **no media-create flow in-framework** (consuming
  apps create media entities). The design must establish a convention: either (a) a documented
  `media` file-uri field (e.g. `source_uri`) the download resolver reads, or (b) resolve via the
  media source plugin for the bundle. (a) is simpler and recommended for v1.

### 3.4 Opt-in private uploads
`MediaRouter` gains a way to mark an upload private (a request flag and/or per-media-type config).
A private upload writes to the private root and returns the **authorized download route** as its
URL, not `/files/`. Public uploads are unchanged (`public://` + `/files/`).

## 4. Migration / classification concern (per the brief)
- **No storage migration of existing files.** Existing `public://` files stay public; nothing
  moves. The change is **additive**.
- **No existing URLs break.** `/files/<name>` for public assets is untouched; private is a new
  surface.
- **Classification is explicit, not guessed.** Default remains **public** (preserves current
  behavior); **private is opt-in per upload / per media-type**. No automatic reclassification of
  existing assets (that would be a guess about sensitivity). Avatars and other public assets stay
  `public://` by default. A consuming app that wants an existing asset private re-uploads it
  private (or a later, separate migration tool handles bulk reclassification — out of scope here).

## 5. Tradeoffs & risk
- **Pro:** closes the bytes-vs-record gap with the entity's own policy; deny-by-default +
  fail-closed + 404-on-deny; no migration, no URL breakage; public assets unaffected.
- **Con / cost:** new capability spanning a private root, a download controller + route **per
  entity type** (media, attachment), the media file-uri field convention (§3.3), opt-in upload
  plumbing, and tests. Medium effort, medium risk (new authorized surface — must get the
  deny-by-default/existence-oracle semantics exactly right; per-entity-type file resolution).
- **Not a one-route drop-in** — hence scoped before building.

## 6. Open decisions (need a go before implementing)
1. **Download route shape** — entity-keyed (`/media/{id}/download`, `/attachment/{id}/download`)
   as proposed, vs a single generic `/download/{type}/{id}`?
2. **Private root location** — `storage/private-files/` (outside the `/files` tree), confirmed?
3. **media file-uri convention** — add a documented `source_uri` field on `media`, vs resolve via
   the media source plugin?
4. **Scope** — media + attachment in one pass, or media first then attachment?
5. **Range requests / large files** — support `Range`/streaming for video now, or v1 = whole-file
   StreamedResponse?

Once these are agreed, implementation is a single failing-first mission: an unauthorized request
for a restricted file's bytes returns 404 post-fix (and would have returned the bytes via a
host-served `/files/` symlink pre-fix); an authorized request streams the bytes; public assets
unaffected.
