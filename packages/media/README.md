# waaseyaa/media

**Layer 2 — Content Types**

Media entity type for Waaseyaa applications.

Defines the `media` entity type for images, documents, and other binary assets. Includes upload handling, file metadata storage, and access policy integration. Managed via the admin SPA and JSON:API endpoint.

Key classes: `Media`, `MediaAccessPolicy`, `MediaServiceProvider`.

## Access scope (important)

`MediaAccessPolicy` gates both the media record and the entity-keyed byte route. `GET /media/{id}/download` reads the media entity's explicit `source_uri`, accepts only contained `public://` paths under `files_root`, and streams bytes only after a deny-by-default `view` decision. Missing, denied, malformed, non-public, and absent-byte requests all collapse to 404. Hosts must use this route—not a public `/files/` symlink—when entity access is intended to protect bytes.

The `MediaType.source` plugin id (`file`/`image`/`oembed`) remains **metadata only**; this narrow download route does not add a source-plugin system, derivatives, or private storage.
