# #2985 — media / upload / storage audit

Audit lane of **#2985** (framework-wide competing-implementation audit).
Read-only. No runtime change, no class removal, no public-surface
reclassification is proposed or performed here.

- **Pinned source SHA:** `8747683eaa0c063995a7560e6ef4c480c2ec5b4c` (`origin/main` at audit time)
- **Identity:** all findings are against that commit on `main`. No unmerged
  candidate branch was consulted. Where a finding depends on a landed fix, the
  fix commit is named.
- **Leads, not findings:** #2759, #2794, #1742, #1762, #2182 were taken as
  starting points and re-derived. Two are confirmed, one is confirmed and
  understated, one is **inverted**, and one is bounded by a missing subsystem.

## 1. Scope and coverage matrix

| Area | Reviewed | Depth | Notes |
|---|---|---|---|
| `packages/media/src` (22 files) | ✅ | Full file inventory + traced upload/download paths | |
| `packages/attachment/src` (15 files) | ✅ | Entity, policy, download router, `PrivateFileStore` | No upload entrypoint exists |
| `packages/ai-tools/src/Content/MediaAssetStore.php` | ✅ | Full read | Third upload path; L5 → L2, legal |
| Upload entrypoints, repo-wide | ✅ | `UploadedFile` / `$_FILES` / `php://input` searches | Exactly two entrypoints |
| Upload authorization | ✅ | Both paths traced to `EntityAccessHandler` | |
| Download authorization | ✅ | `MediaDownloadRouter`, `AttachmentDownloadRouter` | |
| URL generation | ✅ | All producers enumerated | |
| JSON:API serialization of media | ✅ | `ResourceSerializer` attribute path | |
| Storage/persistence | ✅ | Byte writer, sidecar store, entity save | |
| CAS / versioning (`media/src/Version/`) | ✅ | Wiring only | Parked — see F4 |
| **Admin SPA media UI** | ⚠️ Partial | One component read (`FileUpload.vue`) | Not a full SPA audit |
| **SSR media rendering** | ⚠️ Partial | `FieldFormatterRegistry` wiring only | |
| **Deployment/docroot layout** | ❌ Not reviewed | — | Blocks confirming H1; needs infra input |
| **`geo` EXIF / image metadata** | ❌ Not reviewed | — | Out of lane |
| **Queue-based / async upload** | ❌ Not reviewed | — | No evidence any exists |
| **Consumer apps (Minoo, Sheg, rhtcircle)** | ❌ Not reviewed | — | Out of repository |
| **Test suites** | ❌ Not run | — | Audit is static; no broad suites per scope |

## 2. Actual call paths and implementation ownership

**Upload path A — HTTP multipart (the framework's real upload route).**
`POST /api/media/upload` registered eagerly at
`packages/foundation/src/Kernel/BuiltinRouteRegistrar.php:80-87`
→ `MediaRouter::handle()` (`packages/media/src/Http/Router/MediaRouter.php:39-51`)
→ `checkCreateAccess('media', $bundle, principal)` (`:66-70`, fail-closed)
→ `new UploadHandler($filesRoot, $allowedMimeTypes)` **constructed inline** (`:111`)
→ content sniff via `finfo` (`:118`), client `Content-Type` never consulted (`:113-116`)
→ `UploadedFile::move()` (`:149`) — Symfony, **not** `UploadHandler::moveUpload()`
→ `LocalFileRepository::save()` writes a JSON sidecar (`LocalFileRepository.php:25-48`)
→ on throw, `@unlink($destPath)` compensates (`:162`).

**Upload path B — AI agent, base64.**
`ContentToolSet` registers tool `{prefix}.upload` only when the app supplies an
`AssetStoreInterface` (`packages/ai-tools/src/Content/ContentToolSet.php:286-300`)
→ `MediaAssetStore::upload()` (`packages/ai-tools/src/Content/MediaAssetStore.php:91`)
→ `checkCreateAccess` (`:92-94`, fail-closed)
→ `new UploadHandler($this->uploadsDir, self::APPROVED_TYPES, $this->maxSizeBytes)` (`:116`)
→ bytes stored content-addressed as `sha256.ext` (`:134`)
→ `media` catalog row saved with scheme-qualified `source_uri` (`:154`).

**Download path A — authorized media.** `MediaDownloadRouter::handle()`
(`packages/media/src/Http/Router/MediaDownloadRouter.php:46-127`): authenticated
principal required (404 on mismatch, avoiding existence leakage), entity `view`
check (`:73-77`), `realpath` containment (`:142-164`), MIME **re-sniffed at serve
time** (`:85`), `X-Content-Type-Options: nosniff`, `Accept-Ranges: none`, whole
file buffered via `file_get_contents` (no streaming).

**Download path B — attachment.** `AttachmentDownloadRouter` +
`PrivateFileStore::resolve()` (`packages/attachment/src/Storage/PrivateFileStore.php:34`),
private path resolution with realpath containment. Attachment has **no upload
entrypoint at all**.

### Ownership

| Capability | Owner | Entrypoint | Downstream |
|---|---|---|---|
| HTTP media upload | `waaseyaa/media` (L2) | `MediaRouter` | `UploadHandler` (inline) → `LocalFileRepository` |
| Authorized media download | `waaseyaa/media` (L2) | `MediaDownloadRouter` | `MediaDownloadSourceReaderInterface` |
| Agent asset upload | `waaseyaa/ai-tools` (L5) | `ContentToolSet` | `MediaAssetStore` → `UploadHandler` (inline) → `EntityRepository` |
| Attachment download | `waaseyaa/attachment` (L2) | `AttachmentDownloadRouter` | `PrivateFileStore` |

`ai-tools` (L5) requires `waaseyaa/media` (L2) — downward, legal
(`bin/check-package-layers:105,131`). `MediaAssetStore` **composes** the media
catalog rather than forking it, so the packages are not competing owners of the
entity; they are competing owners of *policy* (F1) and *URL shape* (F2).

## 3. Confirmed findings

### F1 — Divergent upload-policy authorities, one of them dead wiring
**CONFIRMED. Affects #2759, which understates it.**

`MediaServiceProvider.php:67-71` binds `UploadHandler::class` as a singleton
reading `media.upload_path` / `media.allowed_types` / `media.max_size`.
**Nothing resolves that binding** — verified directly: every other reference to
`UploadHandler` in `packages/*/src` is either the class itself, `MediaRouter:111`'s
own `new`, or `MediaAssetStore:116`'s own `new`. The live route reads a
different config namespace (`files_root`, `upload_max_bytes`,
`upload_allowed_mime_types`, `MediaRouter.php:216-255`).

Three policy statements, disagreeing:

| Site | Max size | Allowed types |
|---|---|---|
| `UploadHandler::DEFAULT_*` (`UploadHandler.php:16-18`) | 5 MiB | jpeg/png/gif/webp |
| `MediaRouter` (live) (`:216-255`) | 10 MiB | + pdf, text/plain |
| `MediaAssetStore::APPROVED_TYPES` (`:51`) | 5 MiB | png/jpeg/webp only |

Concrete divergence: a 6 MiB `text/plain` upload is **accepted** by the live
route and **rejected** by the provider-bound handler. #2759's title names two
authorities ("provider and HTTP"); there is a third in `ai-tools`.

The dead binding must not be read as "removable" — it is published surface in
`waaseyaa/media`, and the stability charter governs any removal. The defect is
the *divergence*, not the existence of the class.

### F2 — Agent asset URLs are content-hash keyed and unauthenticated
**CONFIRMED and LIVE. Affects #2794 (`release:beta-blocker`, `area:security`).**

An earlier reading of this audit guessed #2794 was made stale by
`c7f109f19 fix(#2517)`. **That guess was wrong, and is withdrawn.** #2517 fixed
the *lookup*: `MediaAssetStore::get()` now gates on the catalog row's `view`
access (`:231-249`). It did **not** change the *URL handed out*:

- `upload()` returns `'url' => $this->publicUrl($sha, $mime)` (`:172`)
- `get()` returns `rtrim($publicUrlBase,'/') . '/' . $assetId . '.' . $ext` (`:208`)
- `publicUrl()` is `publicUrlBase + sha256 + ext` (`:268-271`)

So the authorization decision is made in-process and then a **static,
content-hash-addressed path** is returned, which does not traverse
`MediaDownloadRouter` and carries no entity key, signature or expiry. Anyone
holding the string — or able to guess a sha256+extension under a known
`publicUrlBase` — reaches the bytes directly if that directory is
document-root served. #2794's stated contract (entity-keyed identity,
authorized delivery) is therefore **not yet met**.

This is a real exposure class, not a style issue. No exploit is recorded here.

### F3 — `source_uri` is emitted raw, and no derivative pipeline exists
**CONFIRMED. Affects #2182; bounded by #1762.**

`ResourceSerializer::attributesFromEntity()` reads `source_uri` verbatim
(`packages/api/src/ResourceSerializer.php:233-252`) and `castAttributes()`
(`:305-323`) has cases for html/boolean/timestamp only — no file/URL case. A
JSON:API `media` resource therefore carries the literal `public://…` string,
not a resolvable URL.

Separately and more decisively: **no thumbnail or derivative pipeline exists
anywhere in the framework.** `MediaType.php:19` states it outright — nothing
resolves a file URI or generates derivatives, tracked in #1762. SSR has an
`ImageFormatter` (`packages/ssr/src/FieldFormatterRegistry.php:36`) but Media's
`source_uri` is declared `type: 'string'` with a `file` widget
(`packages/media/src/Media.php:48-56`), so it is never wired.

Consequence for #2182: it **cannot** be closed by a serializer fix. Showing a
thumbnail requires (a) URI→URL resolution and (b) a derivative decision that
does not exist yet.

### F4 — The CAS park is deliberate; #1742's premise is inverted
**CONFIRMED, and the lead is wrong in direction.**

`MediaServiceProvider::boot()` (`packages/media/src/MediaServiceProvider.php:93-147`)
is an unconditional `return;` preceded by a comment recording that #1942
accidentally **live-activated** `MediaVersionStorageDriver` and
`MediaCascadeDeleteSubscriber` in production by widening dispatcher FQCN
resolution, that #1946 shipped this re-park as a regression fix, and: *"Do NOT
remove this early return without first reading #1742."* The wiring is retained
as a comment for a future finish-or-park decision. `PendingUpload` and
`ContentAddressedFileRepositoryDecorator` both carry
`@internal Parked until #1742's byte-persistence criterion is met.`

So at this SHA the subsystem is **inert, not lossy**: no `media_version` rows
are written, no CAS blobs are created, no cascade delete touches version rows,
and the in-memory `$pendingUploads` array is never populated by a real request.
#1742 is titled "latent data loss" — the park is what *prevents* the data-lossy
cascade delete. The live residual risk is the opposite one: **accidental
re-activation** by a future dispatcher change, which the unconditional return
is specifically designed to prevent.

#1742 should be retitled and rescoped to the finish-or-park decision it
actually is. Its `accepted-risk` / `portfolio:deferred` labels are consistent
with the code; its title is not.

### F5 — Media file metadata lives in two stores
**CONFIRMED. No open issue found.**

`LocalFileRepository::save()` writes a per-URI JSON sidecar
(`packages/media/src/LocalFileRepository.php:25-48`) holding uri, filename,
mimeType, size, ownerId, createdTime, originalName. The `Media` entity row
separately holds name/bundle/status/created/changed/`source_uri`/uid
(`packages/media/src/Media.php:33-67`). Nothing reconciles them; the sidecar is
keyed by URI, the row by entity id. Closed #2758 ("preserve full URI identity in
`LocalFileRepository` sidecars") touched this area but did not consolidate it.

### F6 — Attachment has no upload path
**CONFIRMED.** `packages/attachment/src` contains no upload entrypoint; it is
download-only. Attachment rows are populated through the generic entity API.
This is **justified separation**, not duplication: attachment serves private,
parent-delegated bytes (`ParentDelegatedAccessPolicy`), media serves catalogued
public assets. They should not be consolidated.

## 4. Hypotheses (not confirmed)

- **H1 — static `/files/` exposure.** F2's risk is only realised if the
  uploads directory is document-root served. `public/index.php:24-27` has a
  dev-server static passthrough, and `AttachmentServiceProvider.php:167`
  comments that `/files/` has no authorization layer. **Not confirmed:**
  deployment docroot layout was not reviewed. This is the single most important
  open question and needs infra input, not more code reading.
- **H2 — two-request upload hand-off.** The parked `$pendingUploads` array is
  per-instance, so a set-in-request-1 / save-in-request-2 flow would silently
  no-op. Moot while parked; becomes real if #1742 finishes without replacing
  the store.
- **H3 — non-atomic byte write.** Symfony's `UploadedFile::move()` falls back to
  copy+unlink across filesystems. Whether uploads and temp live on one
  filesystem is deployment-dependent. Note the sidecar writer *does* use
  temp+rename (`LocalFileRepository.php:320-343`).

### Corrected during the audit

One investigator reported `UploadHandler::moveUpload()` as the production byte
writer and inferred a non-atomic-write defect from it. **That is wrong.**
`moveUpload()` and its weaker `assertSafeSubdir()` string check
(`UploadHandler.php:125-177`) have exactly one caller in the repository —
`packages/media/tests/Unit/UploadHandlerTest.php:212`. Production writes via
Symfony at `MediaRouter.php:149`. The weak containment check is therefore **not
attacker-reachable at this SHA**, and the atomicity question belongs to H3.
Recorded because the mistaken version would have produced a false security
finding.

## 5. Existing issues affected — with specific evidence

| Issue | State | Relationship | Evidence |
|---|---|---|---|
| **#2759** | OPEN, beta-blocker | **Confirmed and understated.** Reuse; widen to three authorities | Dead binding `MediaServiceProvider.php:67`, zero resolvers; live route `MediaRouter.php:216-255`; third allowlist `MediaAssetStore.php:51` |
| **#2794** | OPEN, beta-blocker, security | **Confirmed live.** Not fixed by #2517 | `MediaAssetStore.php:172,208,268-271` return hash-keyed static URLs; `:231-249` authorizes only the lookup |
| **#1742** | OPEN, p3, accepted-risk | **Premise inverted.** Retitle/rescope, do not close | `MediaServiceProvider.php:93-147` unconditional park, `@internal Parked…` on `PendingUpload.php:14` |
| **#1762** | OPEN, needs-rescope | **Confirmed blocker for #2182.** Owns URI→URL resolution | `MediaType.php:19` — nothing resolves file URIs |
| **#2182** | OPEN, needs-rescope | **Root cause identified**; cannot be fixed alone | `ResourceSerializer.php:233-252,305-323`; no derivative pipeline anywhere |
| #2517 / #2627 | CLOSED | Fixed the lookup half of #2794 only | `c7f109f19`; `MediaAssetStore` docblock `:30-41` |
| #2758 | CLOSED | Adjacent to F5; did not consolidate the dual store | `LocalFileRepository.php:25-48` |
| #2724 / #2719 | CLOSED | Prior duplication audits; **media not in scope** | Neither body mentions media/upload/attachment |
| #1639 | OPEN | Would add a third upload surface | Should not land before #2759 |

**No new issue is proposed for F1–F4** — existing issues cover them and should
be reused per #2985. **F5 has no owning issue**; recommend folding it into
#2759 rather than opening a ticket, since both are "two authorities for one
concept" in the same package.

## 6. Recommended priorities

1. **#2794 (F2) — highest.** Correctness/security, and the only finding with a
   plausible unauthenticated-exposure path. Resolve H1 first: if the uploads
   directory is not docroot-served, severity drops sharply and the fix becomes
   a contract cleanup rather than an incident.
2. **#2759 (F1) — high.** Two config namespaces for one policy, one of them
   dead, plus a third hardcoded allowlist. Cheap to converge; prevents a
   security-relevant misconfiguration where an operator sets `media.max_size`
   and nothing happens.
3. **#1742 (F4) — retitle now, decide later.** Zero code change needed today.
   The title actively misleads: it says data loss where the code prevents it.
4. **#1762 → #2182 (F3) — sequenced, product-facing.** Not a defect in shipped
   behaviour; a missing capability. Keep off any correctness critical path.
5. **F5 — cleanup**, folded into #2759.

## 7. Compatibility constraints

- `waaseyaa/media`, `waaseyaa/attachment`, `waaseyaa/ai-tools` are all published
  and split-mirrored. **Nothing here authorizes removing a class.** The dead
  `UploadHandler` binding (F1) and the parked CAS surfaces (F4) have no known
  first-party callers; per the stability charter that is evidence about this
  repository only and does not establish removability.
- Converging F1's config namespaces changes observable behaviour for any
  operator who set `media.max_size` / `media.allowed_types` — today those keys
  do nothing, so honouring them could newly *reject* uploads that currently
  succeed. That is a deprecation-cycle change (charter §4), not a silent fix.
- Changing the `url` returned by `MediaAssetStore` (F2) changes an `@api` class's
  return payload consumed by AI tool schemas. Needs a migration inventory of
  already-issued URLs — #2794's own body asks for exactly this.
- `source_uri` is `FieldReadLevel::Protected` (`Media.php:54`). Any URL-resolution
  fix must not widen that to Public as a side effect.

## 8. Focused acceptance criteria

Each is written to fail for one specific defect.

1. **F1:** with `media.max_size` set to a value smaller than the live default, an
   upload exceeding it is rejected by the HTTP route. *Discriminates:* fails
   today, because the route never reads that key.
2. **F1:** exactly one code path constructs the effective upload policy; an
   architecture test asserts no second `new UploadHandler(...)` outside the
   authority. *Discriminates:* fails on a fourth allowlist appearing.
3. **F2:** a URL returned by `MediaAssetStore::get()` for a principal who may
   view the row, fetched by a principal who may **not**, is refused.
   *Discriminates:* passes trivially today only if the URL is unreachable —
   which is exactly what H1 must establish.
4. **F2:** the returned URL contains the media entity key and does not contain a
   bare content hash. *Discriminates:* fails on today's `sha256.ext`.
5. **F4:** an architecture test asserts `MediaServiceProvider::boot()` registers
   no version subscriber while the park stands. *Discriminates:* fails if a
   future dispatcher change re-activates it — the exact #1946 regression.
6. **F3:** a JSON:API `media` resource's file attribute is fetchable over HTTP by
   an authorized principal. *Discriminates:* fails today (raw `public://`).

None of these should be implemented under this audit.

## 9. Next disjoint audit slice — recommendation

**Queue, scheduler and background-job execution** (`packages/queue`,
`packages/scheduler`, `packages/notification`).

Rationale: it is disjoint from Codex's active generation/search repairs and
from the ingestion documentation checkpoint; #2818 ("queue: define an isolated
worker boundary for filesystem, process, network and resources") and #2822 and
#2745 ("persistent async notifications lose recipient routing") are open leads
suggesting the same competing-implementation shape; and it is the natural place
where any *deferred* media work (derivative generation, durable upload
hand-off) would eventually execute, so auditing it now informs #1742 and #1762
before either is designed.

Explicitly **not** recommended next: anything in `packages/cli/src/Site/` or
`packages/search` (Codex is active there), or `packages/ingestion` (checkpointed
under #2984).
