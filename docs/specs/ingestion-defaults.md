# Ingestion Defaults

<!-- Spec reviewed 2026-04-04a - @internal annotations added to EnvelopeValidator and PayloadValidatorInterface, no behavioral changes -->

## Purpose

Defines the ingestion pipeline's envelope schema, validation rules, canonical error format, structured logging, and CI enforcement. All ingestion operations follow these conventions to ensure predictable, operator-diagnosable behavior.

## Architecture Overview

The ingestion pipeline processes data through three validation phases:

```
Raw input → EnvelopeValidator → PayloadValidator → Pipeline
              (shape check)     (content-type check)  (processing)
```

Each phase produces canonical `IngestionError` objects. Both success and failure outcomes are logged via `IngestionLogger`.

### Key Classes

| Class | Package | Purpose |
|-------|---------|---------|
| `Envelope` | foundation | Immutable DTO for validated envelopes |
| `EnvelopeValidator` | foundation | Validates raw arrays against envelope schema |
| `PayloadValidator` | foundation | Validates payload against content-type schema |
| `IngestionError` | foundation | Canonical error value object |
| `IngestionErrorCode` | foundation | Error code enum (ENVELOPE_*, PAYLOAD_*) |
| `IngestionLogEntry` | foundation | Unified log entry for success/failure |
| `IngestionLogger` | foundation | JSONL file logger with retention pruning |
| `InvalidEnvelopeException` | foundation | Exception carrying `list<IngestionError>` |

## Envelope Schema

The canonical ingestion envelope is defined in `defaults/ingestion.envelope.schema.json`:

### Required Fields

| Field | Type | Description |
|-------|------|-------------|
| `source` | string (min 1) | Origin of the data (e.g., "manual", "api", "rss") |
| `type` | string (min 1) | Content type identifier (e.g., "core.note") |
| `payload` | object | Content-type-specific data |
| `timestamp` | string (ISO 8601) | When the data was created/captured |

### Optional Fields

| Field | Type | Description |
|-------|------|-------------|
| `trace_id` | string (UUID v4) | Correlation ID; auto-generated if absent |
| `tenant_id` | string (min 1) | Multi-tenant isolation key |
| `metadata` | object | Arbitrary key-value metadata |

### Example — Valid Envelope

```json
{
  "source": "manual",
  "type": "core.note",
  "payload": {
    "title": "Project Update",
    "body": "Sprint 3 is complete.",
    "tenant_id": "tenant-1"
  },
  "timestamp": "2026-03-08T17:00:00+00:00",
  "trace_id": "550e8400-e29b-41d4-a716-446655440000",
  "tenant_id": "tenant-1",
  "metadata": {
    "priority": "high"
  }
}
```

## Validation Rules

### Phase 1: Envelope Validation (`EnvelopeValidator`)

1. **Unknown fields rejected** — only the 7 allowed fields are accepted
2. **Required fields checked** — source, type, payload, timestamp must be present
3. **Type checking** — source/type must be strings, payload must be an object, etc.
4. **Format validation** — timestamp must parse as ISO 8601, trace_id must be a lowercase UUID
5. **Auto-generation** — trace_id is generated via `Uuid::v4()` if absent

On success, returns an `Envelope` DTO. On failure, throws `InvalidEnvelopeException` with structured errors.

### Phase 2: Payload Validation (`PayloadValidator`)

Validates `envelope.payload` against the content-type's JSON Schema from `DefaultsSchemaRegistry`:

1. **Schema lookup** — loads the schema for `envelope.type`
2. **readOnly field rejection** — fields marked `readOnly: true` must not appear in ingestion payloads
3. **Required field check** — required fields (excluding readOnly) must be present
4. **Type checking** — string, integer, number, boolean, array, object
5. **String constraints** — minLength, maxLength enforcement
6. **Unknown field rejection** — when `additionalProperties: false`

Returns `list<IngestionError>` (empty on success).

## Canonical Error Format

Every ingestion error is an `IngestionError` value object:

```php
new IngestionError(
    code:    IngestionErrorCode::PAYLOAD_FIELD_MISSING,
    message: "Required field 'title' is missing.",
    field:   'title',
    traceId: '550e8400-e29b-41d4-a716-446655440000',
    details: [],
);
```

### Serialized Shape

```json
{
  "code": "PAYLOAD_FIELD_MISSING",
  "message": "Required field 'title' is missing.",
  "field": "title",
  "trace_id": "550e8400-e29b-41d4-a716-446655440000",
  "details": {}
}
```

### Error Codes

| Code | Phase | Meaning |
|------|-------|---------|
| `ENVELOPE_FIELD_MISSING` | Envelope | Required field absent |
| `ENVELOPE_FIELD_TYPE_INVALID` | Envelope | Wrong type (e.g., int where string expected) |
| `ENVELOPE_FIELD_EMPTY` | Envelope | String field is empty/blank |
| `ENVELOPE_FIELD_UNKNOWN` | Envelope | Unrecognized top-level field |
| `ENVELOPE_TIMESTAMP_INVALID` | Envelope | Timestamp not valid ISO 8601 |
| `ENVELOPE_TRACE_ID_INVALID` | Envelope | trace_id not a valid lowercase UUID |
| `PAYLOAD_SCHEMA_NOT_FOUND` | Payload | No schema registered for this type |
| `PAYLOAD_SCHEMA_LOAD_FAILED` | Payload | Schema file could not be read/parsed |
| `PAYLOAD_FIELD_MISSING` | Payload | Required payload field absent |
| `PAYLOAD_FIELD_TYPE_INVALID` | Payload | Payload field type mismatch |
| `PAYLOAD_FIELD_TOO_SHORT` | Payload | String below minLength |
| `PAYLOAD_FIELD_TOO_LONG` | Payload | String exceeds maxLength |
| `PAYLOAD_FIELD_READ_ONLY` | Payload | readOnly field submitted in ingestion |
| `PAYLOAD_FIELD_UNKNOWN` | Payload | Undefined field with additionalProperties: false |

### Error Example — Multiple Failures

```json
[
  {
    "code": "PAYLOAD_FIELD_READ_ONLY",
    "message": "Field 'id' is read-only and must not be included in ingestion payloads.",
    "field": "id",
    "trace_id": "550e8400-e29b-41d4-a716-446655440000",
    "details": {}
  },
  {
    "code": "PAYLOAD_FIELD_MISSING",
    "message": "Required field 'title' is missing.",
    "field": "title",
    "trace_id": "550e8400-e29b-41d4-a716-446655440000",
    "details": {}
  }
]
```

## Structured Logging

`IngestionLogger` writes to `storage/framework/ingestion.jsonl`. Both success and failure events use the same `IngestionLogEntry` structure.

### Log Entry Shape

| Field | Type | Present | Description |
|-------|------|---------|-------------|
| `source` | string | always | Envelope source |
| `type` | string | always | Content type |
| `status` | string | always | "accepted" or "rejected" |
| `trace_id` | string | always | Correlation ID |
| `timestamp` | string | always | Envelope timestamp |
| `logged_at` | string | always | When the log entry was created |
| `tenant_id` | string | when set | Tenant isolation key |
| `errors` | array | on failure | Serialized `IngestionError[]` |

### Example — Success Log

```json
{
  "source": "manual",
  "type": "core.note",
  "status": "accepted",
  "trace_id": "550e8400-e29b-41d4-a716-446655440000",
  "timestamp": "2026-03-08T17:00:00+00:00",
  "logged_at": "2026-03-08T17:00:01+00:00",
  "tenant_id": "tenant-1"
}
```

### Example — Failure Log

```json
{
  "source": "manual",
  "type": "core.note",
  "status": "rejected",
  "trace_id": "550e8400-e29b-41d4-a716-446655440000",
  "timestamp": "2026-03-08T17:00:00+00:00",
  "logged_at": "2026-03-08T17:00:01+00:00",
  "tenant_id": "tenant-1",
  "errors": [
    {
      "code": "PAYLOAD_FIELD_MISSING",
      "message": "Required field 'title' is missing.",
      "field": "title",
      "trace_id": "550e8400-e29b-41d4-a716-446655440000",
      "details": {}
    }
  ]
}
```

### Retention

Default retention is 90 days. `IngestionLogger::prune()` removes entries older than the retention period using atomic file rewriting.

### Factory Methods

| Method | Status | Input |
|--------|--------|-------|
| `IngestionLogEntry::success($envelope)` | accepted | Validated Envelope |
| `IngestionLogEntry::envelopeFailure($traceId, $source, $type, $errors)` | rejected | Raw data + errors |
| `IngestionLogEntry::payloadFailure($envelope, $errors)` | rejected | Envelope + payload errors |

## x-waaseyaa Schema Metadata

Every `defaults/*.schema.json` includes an `x-waaseyaa` extension block:

| Field | Required | Values | Default |
|-------|----------|--------|---------|
| `entity_type` | yes | Must match filename (e.g., `core.note`) | — |
| `version` | yes | Semver (e.g., `0.1.0`) | — |
| `compatibility` | yes | `liberal` or `strict` | — |
| `schema_kind` | no | `entity` or `ingestion_envelope` | `entity` |
| `stability` | no | `experimental`, `stable`, `deprecated` | `stable` |

### Versioning Rules

- `compatibility: liberal` — minor additions allowed without version bump
- `compatibility: strict` — any change requires a version bump
- `stability: experimental` — subject to breaking changes without notice
- `stability: stable` — follows semver guarantees
- `stability: deprecated` — scheduled for removal

## CI Enforcement

The `ingestion-defaults` CI job runs `bin/check-ingestion-defaults`:

1. **x-waaseyaa metadata** — every schema must have entity_type, version (semver), compatibility (liberal/strict)
2. **schema_kind validation** — must be `entity` or `ingestion_envelope`
3. **stability validation** — must be `experimental`, `stable`, or `deprecated`
4. **Envelope presence** — `ingestion.envelope.schema.json` must exist with required fields
5. **Filename consistency** — entity_type must match the filename convention

These checks run alongside existing CI gates:
- `manifest-conformance` — JSON well-formedness, draft-07 validity, project_versioning
- `security-defaults` — no secrets in defaults/

## File Locations

| File | Purpose |
|------|---------|
| `defaults/ingestion.envelope.schema.json` | Canonical envelope schema |
| `defaults/core.note.schema.json` | Example content-type schema |
| `packages/foundation/src/Ingestion/` | All ingestion classes |
| `packages/foundation/tests/Unit/Ingestion/` | Unit tests |
| `bin/check-ingestion-defaults` | CI enforcement script |
| `storage/framework/ingestion.jsonl` | Runtime log file |
