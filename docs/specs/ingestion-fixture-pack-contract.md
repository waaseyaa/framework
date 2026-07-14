# Ingestion Fixture Pack Contract

## Scope

- Goal: provide a deterministic, versioned fixture corpus for the production ingestion pipeline and replay safety.

## Fixture Corpus Paths

- Ingestion inputs:
  - `tests/fixtures/ingestion/structured-valid.input.json`
  - `tests/fixtures/ingestion/structured-schema-invalid.input.json`
  - `tests/fixtures/ingestion/structured-validation-invalid.input.json`
  - `tests/fixtures/ingestion/structured-inference.input.json`
- Scenario pack seeds:
  - `tests/fixtures/scenarios/ingestion-ready.json`
  - `tests/fixtures/scenarios/ingestion-review.json`
  - `tests/fixtures/scenarios/ingestion-blocked.json`

## Required Coverage

- Structured valid ingestion success and replay determinism
- Schema failure (`schema.duplicate_source_uri`)
- Validation failure (`validation.semantic.insufficient_publishable_tokens`)
- Inference coverage (`inference.relationship_inferred`)
- Fixture-pack aggregate determinism (`fixture:pack:refresh` hash stability)

`IngestionFixturePackRegressionTest` replays these fixtures through `ingest:run`, checks deterministic output, covers schema/validation/inference diagnostics, and validates repeated fixture-pack refreshes.

## Determinism Rules

- All fixture files are static and version-controlled.
- Ingest replay tests use fixed options (`batch_id`, `timestamp`, policy/source settings).
- Scenario aggregate order is deterministic by sorted filenames and sorted keys.
