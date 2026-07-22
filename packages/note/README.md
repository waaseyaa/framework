# waaseyaa/note

**Layer 2 — Content Types**

Note entity type for Waaseyaa applications.

Defines the `note` entity type for lightweight, unstructured content entries (e.g. editorial annotations, internal notes). Simpler than `node` — no bundles, no editorial workflow. See `docs/specs/ingestion-defaults.md` for ingestion context.

Admin/API-created notes carry a server-attributed `uid`. Administrators and authorized owners can read, list, update, and delete note rows; the immutable `core.note` type definition itself remains non-deletable.

Key classes: `Note`, `NoteAccessPolicy`, `NoteServiceProvider`.

## Ingestion (`Note\Ingestion\*`) — experimental, not wired

The `Note\Ingestion\` classes (`NoteIngester`, `IngestionEnvelope`, `IngestionEnvelopeValidator`, `ValidationError`) build a `note` entity from a validated ingestion envelope. They are **experimental and not wired into any runtime path** — no service provider, route, CLI command, or queue handler constructs `NoteIngester` or calls `->ingest()`; only the package's own unit tests and `tests/Integration/Phase19/NoteIngestionIntegrationTest.php` exercise them. The `ingestion.envelope` **schema** itself is live but marked `experimental` (registered in `defaults/ingestion.envelope.schema.json`, surfaced by the `schema:list` CLI). To ingest a note today you must call `NoteIngester` directly; there is no end-to-end ingestion entry point.
