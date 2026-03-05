# Ingestion Validator Contract (v1.5)

## Status

- Contract stability: **frozen for v1.5**.
- Renames/removals are not allowed during v1.5.
- Additions must be additive, optional, flat, and scalar-typed.

## Scope

This spec defines the canonical schema-layer validator contract for `#163`.
It governs envelope normalization, pointer generation, diagnostic shape, and deterministic ordering.

## Envelope Contract

Top-level required fields:

- `batch_id`
- `source_set_uri`
- `policy`
- `items`

Top-level behavior:

- Envelope paths are validated against the normalized structure.
- `items` is the canonical item list for pointer generation.

## Normalization Rules

### 1. String normalization

- Trim leading/trailing whitespace for contract strings.
- Required fields that become empty after trim are invalid.
- Preserve case except where explicitly normalized (policy and URI scheme).

### 2. Policy normalization

- Normalize `policy` to lowercase.
- Allowed values:
  - `atomic_fail_fast`
  - `validate_only`

### 3. `source_set_uri` normalization

- Required format: `<scheme>://<identifier>`.
- Normalize `scheme` to lowercase.
- Preserve identifier value after trim.
- Allowed schemes (closed list):
  - `dataset`
  - `crawl`
  - `manual`
  - `api`
  - `file`

### 4. Item normalization

- `items` must be an array.
- Preserve item order from submission.
- Canonical pointer indices are zero-based (`/items/0`, `/items/1`, ...).

### 5. Item provenance normalization

Per-item required fields:

- `source_uri`
- `ingested_at`

Per-item nullable field:

- `parser_version`

Rules:

- Trim string values.
- Empty required values are invalid.
- Missing `parser_version` is normalized to `null`.

### 6. Duplicate detection

- Enforce unique `source_uri` within a batch.
- Duplicate comparison uses normalized `source_uri` (post-trim exact match).
- Cross-batch duplicates are not schema violations.

## Pointer Rules

Canonical location rules:

- `location` always points to the normalized envelope.
- JSON Pointer syntax only (RFC 6901 style).
- Zero-based item indexing only.
- Point to exact failing field, not item root.

Examples:

- `/source_set_uri`
- `/policy`
- `/items/0/source_uri`
- `/items/2/ingested_at`

Raw pointer rules:

- Optional `context.raw_location` may be included only when exact raw mapping is known.
- `context.raw_location` must use JSON Pointer syntax.
- If exact raw mapping is unknown, omit `raw_location`.

## Diagnostic Namespace Reservation

Reserved namespaces:

- `schema.*` (active in `#163`)
- `validation.*` (reserved for `#165`)
- `inference.*` (reserved for `#166`)
- `runtime.*` (reserved)
- `semantic.*` (reserved for `#168`)

`#163` schema validator emits `schema.*` only.

## Schema Diagnostic Envelope (Frozen)

Each schema diagnostic object is contract-stable and must include:

- `code`
- `message`
- `location`
- `item_index`
- `context`

### Field constraints

- `code`: stable machine key under `schema.*`.
- `message`: fixed template text.
- `location`: normalized JSON Pointer.
- `item_index`: integer for item-level errors, `null` for batch-level errors.
- `context`: flat, strictly typed map.

### Context constraints

`context` must be flat and deterministic:

- scalar values only (`string`, `number`, `boolean`, `null`),
- arrays of scalars are allowed where required (e.g., `allowed_schemes`),
- nested objects are not allowed.

Mandatory context keys:

- `value` (required; may be `null` if unrecoverable)
- `expected` (required; may be `null` when no explicit expectation exists)

Optional context keys:

- `raw_location`
- additional flat scalar keys as needed (additive only)

### Context key order

Canonical key order:

1. `value`
2. `expected`
3. `raw_location` (if present)
4. remaining keys sorted alphabetically

## Schema Error Codes (v1.5)

Initial frozen code set:

- `schema.duplicate_source_uri`
- `schema.malformed_source_set_uri`
- `schema.unknown_source_set_scheme`
- `schema.missing_required_provenance_field`
- `schema.invalid_policy_value`
- `schema.missing_required_envelope_field`
- `schema.invalid_items_type`
- `schema.malformed_ingested_at`
- `schema.malformed_batch_id`
- `schema.malformed_source_uri`
- `schema.invalid_parser_version_type`

## Fixed Message Templates

Message text must remain stable in v1.5.

- `schema.duplicate_source_uri`:
  - `Duplicate source_uri detected: "<value>". Each item in a batch must have a unique source_uri.`
- `schema.malformed_source_set_uri`:
  - `Malformed source_set_uri: "<value>". Expected format: "<scheme>://<identifier>".`
- `schema.unknown_source_set_scheme`:
  - `Unknown source_set_uri scheme: "<value>". Allowed schemes: <allowed_schemes>.`
- `schema.missing_required_provenance_field`:
  - `Missing required provenance field: "<field_name>".`
- `schema.invalid_policy_value`:
  - `Invalid ingestion policy: "<value>". Allowed policies: <allowed_policies>.`
- `schema.missing_required_envelope_field`:
  - `Missing required envelope field: "<field_name>".`
- `schema.invalid_items_type`:
  - `Invalid items field type: "<value>". Expected: "<expected>".`
- `schema.malformed_ingested_at`:
  - `Malformed ingested_at value: "<value>". Expected: "<expected>".`
- `schema.malformed_batch_id`:
  - `Malformed batch_id value: "<value>". Expected: "<expected>".`
- `schema.malformed_source_uri`:
  - `Malformed source_uri value: "<value>". Expected: "<expected>".`
- `schema.invalid_parser_version_type`:
  - `Invalid parser_version type: "<value>". Expected: "<expected>".`

## Severity Contract

Schema-layer diagnostics are errors-only in v1.5.

- No `warning`/`info` severity at schema layer.
- Fail behavior by mode:
  - `atomic_fail_fast`: schema errors reject batch before processing.
  - `validate_only`: schema errors returned as diagnostics without side effects.

## Diagnostic Aggregation and Ordering

Output model:

- one diagnostic entry per violation,
- optional `meta.summary` may include:
  - `error_count`
  - `warning_count` (always `0` for schema layer)
  - `codes` (sorted unique)

Canonical diagnostic ordering:

1. `item_index` (`null` sorts before numeric)
2. `code`
3. failing field (tail segment of `location`)

This ordering is contract-stable for fixtures and replay verification.

## Example Diagnostic

```json
{
  "code": "schema.duplicate_source_uri",
  "message": "Duplicate source_uri detected: \"article-123\". Each item in a batch must have a unique source_uri.",
  "location": "/items/0/source_uri",
  "item_index": 0,
  "context": {
    "value": "article-123",
    "expected": "unique within batch",
    "raw_location": "/items/0/source_uri"
  }
}
```

## Compatibility Notes

- This spec is the canonical substrate for `#165`, `#167`, `#169`, and `#170`.
- Any v1.5 updates must be additive and backward-compatible.
