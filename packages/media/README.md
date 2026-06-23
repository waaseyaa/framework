# waaseyaa/media

**Layer 2 — Content Types**

Media entity type for Waaseyaa applications.

Defines the `media` entity type for images, documents, and other binary assets. Includes upload handling, file metadata storage, and access policy integration. Managed via the admin SPA and JSON:API endpoint.

Key classes: `Media`, `MediaAccessPolicy`, `MediaServiceProvider`.

## Access scope (important)

`MediaAccessPolicy` gates the media **record** (the entity row) on the JSON:API surface — view/create/update/delete of the `media` entity. It does **not** gate the file **bytes**: uploads are written to the `public://` scheme (`storage/files`, returned as a `/files/<name>` URL) and are served by the host's normal static path, **not** through an access-checked download route. There is currently **no `private://` scheme or authorized-download path for media** (unlike `attachment`, which got `GET /attachment/{id}/download` with deny-by-default access in #1761). So a media entity's access policy does not protect its file contents from direct URL access — do not store sensitive bytes as `public://` media expecting the entity policy to guard them.

The `MediaType.source` plugin id (`file`/`image`/`oembed`) is **metadata only** — the media source-plugin system (resolution, derivatives, gated downloads) is not implemented. Both — the source-plugin substrate and an authorized media download reusing the attachment `PrivateFileStore` — are tracked as a future feature in #1762.
