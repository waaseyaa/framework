# Contract: Entity Agent Tool Write Protection

**Mission**: live-entity-validation-key-protection-01KTWQT3 | **Requirements**: FR-005..FR-007, NFR-002

Applies to the stock tools `entity.create` and `entity.update` (`packages/ai-tools/src/Entity/`), over every transport that dispatches them (in-app agent, MCP).

## Identity-key refusal

1. **Refusal set** per entity type: registered key columns for kinds `id`, `uuid`, `revision`, `langcode`, `default_langcode`, unioned with literals `uuid`, `langcode`, `default_langcode`. `label` and `bundle` kinds are never refused.
2. **Whole-write rejection**: if the `values` payload contains ANY refused key, the tool returns an error result; no entity is constructed (create) and no field is set (update). Partial application is forbidden.
3. **Error shape**:
   - message: `entity.<op>: refused identity keys: <k1>, <k2> — identity fields cannot be written through this tool` (keys sorted alphabetically)
   - payload (when error content is supported): `{ "error": "identity_keys_refused", "refused_keys": [...] }`
4. **Order of checks**: capability → argument shape → entity-type existence → access → **identity-key refusal** → mutation/validation. (Refusal must not leak entity existence to callers lacking access.)
5. **dry-run**: reports the refusal the same way (a dry run of an invalid call must not claim it would succeed).
6. **revision_log**: stays writable via its dedicated argument; it is content, not identity.

## Validation error pass-through (single seam)

7. Tool writes reach `EntityRepository::save()` with default validation ON — the tools add no private validation fork and no `validate: false`.
8. `EntityValidationException` is caught distinctly (before the generic `\Throwable` arm) and surfaced as:
   - message: `entity.<op>: validation failed: <field>: <message>[; ...]` — violations sorted by field name (NFR-003)
   - payload: `{ "error": "validation_failed", "violations": [ { "field", "message", "invalid_value_type" } ] }`
9. Other throwables keep today's generic error mapping (no behavior change).

## Verification (NFR-002: 100% key coverage)

- Unit: `EntityKeyGuardTest` — every kind in the refusal set, renamed key columns (e.g. `id => nid`), translatable + non-translatable types, label/bundle NOT refused.
- Unit: create + update tool tests — refusal short-circuit (storage never called), error shape, dry-run refusal, validation-exception mapping, sorted determinism.
- Integration: MCP/agent dispatch path exercises `entity.update` with `langcode` and gets the structured refusal (covers the transport).
