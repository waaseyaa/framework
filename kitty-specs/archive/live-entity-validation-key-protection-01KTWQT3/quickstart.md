# Quickstart: Verifying Live Entity Validation & Key Protection

**Mission**: live-entity-validation-key-protection-01KTWQT3

Reviewer's hands-on script — each step maps to an acceptance scenario in [spec.md](spec.md).

## 1. Declared constraints enforce on save (scenarios 1–3)

```bash
./vendor/bin/phpunit tests/Integration/Validation/ --no-progress
```

Or by hand in a booted app/test: register an entity type with an `integer` field carrying `settings: ['min' => 0, 'max' => 100]`, save a value of `150` → expect `EntityValidationException` naming the field and range; save `50` → succeeds.

## 2. Per-field declared constraints honored (scenario 2)

Field definition with `constraints: [new GreaterThan(0)]` → saving `0` fails, `1` passes — without the entity type declaring anything at the type level.

## 3. Agent tools refuse identity keys (scenarios 4–5)

```bash
./vendor/bin/phpunit packages/ai-tools/tests/ --no-progress
```

By hand over MCP/agent dispatch: `entity.update` with `values: {"langcode": "xx"}` → structured error `identity_keys_refused`, row unchanged. Same for `uuid` on update and `id`/`uuid`/`langcode` on `entity.create`.

## 4. Validation errors are model-correctable (scenario 6)

`entity.update` with an out-of-range value → error `validation_failed` with `violations[].field` / `.message`, sorted by field name; correcting the value and retrying succeeds.

## 5. Opt-outs (scenario 7 / edge cases)

```bash
WAASEYAA_ENTITY_VALIDATION=0 ./vendor/bin/phpunit tests/Integration/Validation/ --filter OptOut
```

- Env opt-out → invalid saves pass (pre-mission behavior).
- `$repository->save($entity, validate: false)` → single save bypasses validation.

## 6. Gates

```bash
composer verify   # suite + phpstan + composer policy + dead-code + getQuery gate
```

CHANGELOG check: `[Unreleased]` contains the BREAKING entry describing newly-failing saves, both opt-outs, and the create-tool id refusal (research.md D3).
