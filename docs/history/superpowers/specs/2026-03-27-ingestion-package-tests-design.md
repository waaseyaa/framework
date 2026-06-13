# Ingestion Package Test Coverage Design

**Issue:** #579 — add tests for Ingestion package
**Milestone:** Test Coverage Stabilization (P1)
**Approach:** Unit tests + targeted integration tests (Approach B)

## Context

The `packages/ingestion/` library has 21 unit tests covering `EnvelopeValidator`, `PayloadValidatorInterface`, and `ValidationResult`. The Foundation-layer ingestion classes in `packages/foundation/src/Ingestion/` have **zero test coverage**:

- `Envelope` — immutable validated envelope DTO
- `IngestionError` — error value object with code, message, field
- `IngestionErrorCode` — enum with 14 error codes
- `IngestionLogEntry` — log entry factory (success/failure)
- `IngestionLogger` — JSONL file logger with retention pruning
- `InvalidEnvelopeException` — exception carrying `list<IngestionError>`
- `PayloadValidator` — schema-based payload validator

## Unit Tests — `packages/foundation/tests/Unit/Ingestion/`

### EnvelopeTest

- Construct with all fields, verify properties
- Construct with optional fields omitted (tenantId null, metadata empty)
- `toArray()` round-trip produces expected shape
- Readonly enforcement (can't mutate after construction)

### IngestionErrorTest

- Construct with all fields, verify properties
- Optional fields default correctly (traceId null, details empty)
- `toArray()` produces expected shape with enum code serialized

### IngestionErrorCodeTest

- All 14 cases exist and are distinct
- Covers both ENVELOPE_* and PAYLOAD_* prefixes

### IngestionLogEntryTest

- `success()` produces entry with expected structure
- `envelopeFailure()` includes error details
- `payloadFailure()` includes error details
- All entries include timestamp and traceId

### InvalidEnvelopeExceptionTest

- Carries `list<IngestionError>` accessible after catch
- Message is meaningful
- Extends appropriate base exception

## Integration Tests — `tests/Integration/Ingestion/`

### IngestionLoggerIntegrationTest

Real temp files, no mocks.

- Writes valid JSONL lines for success entries
- Writes valid JSONL lines for failure entries
- Appends to existing log file (doesn't overwrite)
- Creates log file if it doesn't exist
- Retention pruning removes old entries beyond threshold
- Handles concurrent-safe writes (atomic file ops)
- Empty log file is handled gracefully

### PayloadValidatorIntegrationTest

Real validation pipeline, no mocks.

- Valid payload passes validation
- Invalid payload returns `ValidationResult` with errors
- Validates against envelope schema (required fields, versions, entity types)
- Error codes match `IngestionErrorCode` enum values
- Multiple validation errors collected (not short-circuited prematurely)

## Conventions

- PHPUnit 10.5 attributes: `#[Test]`, `#[CoversClass(...)]`, `#[CoversNothing]` for integration
- PHP 8.4+, `declare(strict_types=1)`
- Temp directories via `sys_get_temp_dir() . '/waaseyaa_test_' . uniqid()`
- No mocks on final classes — use real instances
- No `-v` flag on PHPUnit
