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

`MediaAccessPolicy` gates both the media record and the entity-keyed byte route. `GET /media/{id}/download` reads the media entity's explicit `source_uri`, accepts only contained `public://` paths under `files_root`, and streams bytes only after a deny-by-default `view` decision. Missing, denied, malformed, non-public, and absent-byte requests all collapse to 404. Hosts must use this route—not a public `/files/` symlink—when entity access is intended to protect bytes.

The `MediaType.source` plugin id (`file`/`image`/`oembed`) remains **metadata only**; this narrow download route does not add a source-plugin system, derivatives, or private storage.
