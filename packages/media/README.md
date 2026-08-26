# waaseyaa/media

**Layer 2 — Content Types**

Media entity type for Waaseyaa applications.

Defines the `media` entity type for images, documents, and other binary assets. Includes upload handling, file metadata storage, and access policy integration. Managed via the admin SPA and JSON:API endpoint.

Key classes: `Media`, `MediaAccessPolicy`, `MediaServiceProvider`.

## Generic authoring and upload

`media` declares `media_type` as its bundle provider. Its common schema exposes
the canonical `source_uri` value as a file widget; applications provide their
own media-type config entities and bundle-specific fields without framework
bundle-name assumptions.

`GET /api/media/upload` returns only the configured maximum byte size and MIME
allowlist. `POST /api/media/upload` requires multipart data containing `file`
and the selected canonical `bundle`. Both methods require authentication and
`access media`; POST additionally requires the media policy to allow creation
for that exact bundle before bytes are persisted. The upload creates the
existing public file plus metadata sidecar and returns its URI—it does not
create the media entity or activate the parked media-version/CAS subsystem.

## Access scope (important)

`MediaAccessPolicy` gates both the media record and the entity-keyed byte routes.
`GET /media/{id}/download` accepts either the numeric storage ID or the JSON:API
UUID used by int-keyed content resources. After bounded identifier resolution
and an Allowed `view` decision, it resolves only the media entity's explicit
`source_uri` through the typed audited download reader, accepts only contained
`public://` paths under the configured file root, and streams the bytes. The
canonical root key is `files_dir`; legacy `files_root` remains supported and
takes precedence when both are set. Missing, denied, malformed, non-public,
and absent-byte requests all collapse to 404. Hosts must use this route—not a
public `/files/` symlink—when entity access is intended to protect bytes.

`GET /media/{id}/view` is the explicit same-origin embedding surface. It uses
the same identifier resolution, entity-view authorization, audited source
reader, path confinement, filename sanitizer, and indistinguishable 404 as the
download route. It returns `Content-Disposition: inline` only when file-content
sniffing identifies `application/pdf`; stored MIME metadata, filename
extensions, and request headers cannot opt content in. Other content remains an
attachment. The kernel's canonical response policy applies
`X-Frame-Options: SAMEORIGIN` and `X-Content-Type-Options: nosniff`, preserving
same-origin framing while denying cross-origin framing; an explicitly
configured deployment CSP remains intact. Both routes deliberately ignore
Range and return one complete `200` representation with `Accept-Ranges: none`.

Use `/download` for ordinary links and explicit downloads. Use `/view` as an
iframe `src` only when the application wants an authorized PDF viewer. Neither
route makes the backing storage URL public.

The `MediaType.source` plugin id (`file`/`image`/`oembed`) remains **metadata only**; this narrow download route does not add a source-plugin system, derivatives, or private storage.
